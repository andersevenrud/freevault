<?php
/*!
 * FreeVault (c) Copyleft Software AS
 */

require "../freevault.php";

///////////////////////////////////////////////////////////////////////////////
// INITIALIZE
///////////////////////////////////////////////////////////////////////////////

$method     = empty($_SERVER['REQUEST_METHOD']) ? 'GET' : $_SERVER['REQUEST_METHOD'];
$uri        = empty($_SERVER['REQUEST_URI'])    ? '/'   : $_SERVER['REQUEST_URI'];
$data       = $method === 'POST' ? file_get_contents("php://input") : (empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI']);;
$json       = empty($_SERVER['CONTENT_TYPE']) ? false : (!!preg_match("/application\/json/", $_SERVER['CONTENT_TYPE']));
$extclient  = empty($_SERVER['HTTP_USER_AGENT']) ? false : !!preg_match("/freevault-python/", $_SERVER['HTTP_USER_AGENT']);
$lastError  = null;
$page       = '/';

// What pages requires a running session
$restricted = Array(
  '/',
  '/settings'
);

// Page title mapping
$titles     = Array(
  '/'           => 'Home',
  '/register'   => 'Register',
  '/login'      => 'Login',
  '/logout'     => 'Logout',
  '/settings'   => 'Settings',
  '/recover'    => 'Recover Password'
);

// Extract page from URI
if ( preg_match("/^(\/.*)/", $uri, $matches) !== false ) {
  $page = reset($matches);
  if ( strpos($page, '?') !== false ) {
    $page = strstr($page, '?', true); // Without query string!
  }
}

// Sessions
session_start();
if ( empty($_SESSION['user']) ) {
  $_SESSION['user'] = null;
}

if ( !VaultUser::CheckSession() ) {
  // User needs to log back in if session has expired or has become invalid
  if ( ($method === 'POST') && $json ) {
    header('Content-Type: application/json; charset=utf-8');
    print json_encode(Array('error' => 'Your session has expired.', 'result' => null));
    exit;
  }

  header("Location: /login?expired");
  exit;
}

///////////////////////////////////////////////////////////////////////////////
// HTTP GET
///////////////////////////////////////////////////////////////////////////////

if ( isset($_SERVER['PHP_AUTH_USER']) ) {
  if ( !VaultUser::Login($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) ) {
    print "Authentication failure!";
    exit;
  }
}

// Display login if no running session is found
if ( !$_SESSION['user'] && in_array($page, $restricted) ) {
  header("Location: /login");
  exit;
} else if ( $_SESSION['user'] && $page === '/login' ) {
  header("Location: /");
  exit;
}

// Always check if user requests logout
if ( $page === '/logout' ) {
  if ( !VaultUser::Logout() ) {
    $lastError = "Could not log out. Probably a server error. Try again!";
  } else {
    header("Location: /");
    exit;
  }
}

if ( $page === '/login' ) {
  if ( isset($_GET['expired']) ) {
    $lastError = "Your session has expired, you need to log in again!";
  }
}

///////////////////////////////////////////////////////////////////////////////
// HTTP POST
///////////////////////////////////////////////////////////////////////////////

// Both AJAX and normal POST methods will be caught here
if ( $method === 'POST' ) {
  $request  = $json ? (Array)json_decode($data) : $_POST;
  $action   = $json ? (empty($request['action']) ? null : mb_strtolower($request['action'])) : preg_replace("/^\//", "", $page);
  $response = Array("result" => false, "error" => null);
  $redirect = "/";
  $user     = null;

  /*
  if ( isset($request['requestToken']) && ($request['requestToken']) ) {
    $token = md5(uniqid());
    $entry['_token'] = $token;
    $_SESSION['token'] = $token;
  }
   */

  try {

    // POST Requires a session (except for login)
    if ( !in_array($action, Array('login', 'recover', 'register')) && !($user = $_SESSION['user']) ) {
      header("HTTP/1.0 500 Internal Server Error");
      print "No session found!";
      exit;
    }

    switch ( $action )
    {
      // FIXME: This is not the way we want in the future
      case 'register' :
        if ( empty($request['username']) ) throw new Exception("Missing username");
        if ( empty($request['password']) ) throw new Exception("Missing password");
        if ( empty($request['email']) )    throw new Exception("Missing email");

        if ( !filter_var($request['email'], FILTER_VALIDATE_EMAIL) ) {
          throw new Exception("Invalid e-mail address");
        }

        if ( !VaultUser::Register($request['username'], $request['password'], $request['email']) ) {
          throw new Exception("Failed to register user, please try again!");
        }

        VaultUser::Login($request['username'], $request['password']);

        $response['result'] = true;

        break;

      case 'login' :
        if ( empty($request['username']) ) throw new Exception("Missing username");
        if ( empty($request['password']) ) throw new Exception("Missing password");

        if ( !VaultUser::Login($request['username'], $request['password']) ) {
          throw new Exception("Failed to log in, check your credentials");
        }
        $response['result'] = true;

        break;

      case 'recover' :
        if ( empty($request['email']) ) throw new Exception("Missing email");

        if ( !VaultUser::Recover($request['email']) ) {
          throw new Exception("Failed to recover password for: {$request['email']}");
        }

        $response['result'] = "Check your e-mail address for recovery instructions";

        break;

      case 'settings' :
        throw new Exception("This is not implemented yet!"); // TODO

        break;

      case 'edit' :
        /*
        if ( !isset($request['_token']) ) throw new Exception("Missing token. Refresh page and try again!");
        if ( empty($_SESSION['token']) || ($request['_token'] != $_SESSION['token']) ) {
          throw new Exception("Invalid token. Refresh page and try again!");
        }
         */

        // ... continued ...

      case 'create' :
        if ( empty($request['title']) )     throw new Exception("Missing argument: title");
        if ( empty($request['category']) )  throw new Exception("Missing argument: category");
        if ( empty($request['encoded']) )   throw new Exception("Missing argument: encoded data");

        $data = Array(
          'title'         => $request['title'],
          'description'   => empty($request['description']) ? '' : $request['description'],
          'category'      => $request['category'],
          'encoded'       => $request['encoded'],
          'allowed_users' => empty($request['allowed_users']) ? Array() : $request['allowed_users']
        );

        if ( $action === 'edit' ) {
          if ( empty($request['id']) ) throw new Exception("Missing argument: id");

          if ( VaultEntry::EditEntry($request['id'], $data, $user) ) {
            $response['result'] = true;
          } else {
            throw new Exception("Failed to edit entry: {$request['id']}");
          }
        } else {
          if ( $entry = VaultEntry::CreateEntry($data, $user) ) {
            $response['result'] = $entry;
          } else {
            throw new Exception("Failed to create new entry");
          }
        }

        break;

      case 'delete' :
        if ( !isset($request['id']) ) throw new Exception("Missing argument: id");

        if ( VaultEntry::DeleteEntry($request['id'], $user) ) {
          $response['result'] = true;
        } else {
          throw new Exception("Faield to delete entry: {$request['id']}");
        }

        break;

      case 'get' :
        if ( !isset($request['id']) ) throw new Exception("Missing argument: id");

        if ( ($entry = (Array)VaultEntry::GetEntry($request['id'], $user)) ) {
          $response['result'] = $entry;
        } else {
          throw new Exception("Failed to load entry: {$request['id']}");
        }

        break;

      case 'list' :
        if ( !isset($request['category']) ) throw new Exception("Missing argument: category");

        if ( ($entries = VaultEntry::GetEntries($request['category'], $user)) !== false ) {
          $response['result'] = $entries;
        } else {
          throw new Exception("Failed to load entries in: {$request['category']}");
        }

        break;

      case 'search' :
        if ( empty($request['query']) ) throw new Exception("Missing argument: query");
        $category = empty($request['category']) ? null : $request['category'];

        if ( ($entries = VaultEntry::GetEntriesSearch($category, $request['query'], $user)) !== false ) {
          $response['result'] = $entries;
        } else {
          throw new Exception("Failed to search with query: {$request['query']}");
        }

        break;

      case 'categories' :
        $response['result'] = VaultEntry::GetCategories($user);

        break;

      default :
        throw new Exception("Invalid action '$action'");

        break;
    }
  } catch ( Exception $e ) {
    $response['error'] = $e->getMessage();
  }

  if ( $json ) {
    header('Content-Type: application/json; charset=utf-8');
    print json_encode($response);
    exit;
  } else {
    if ( $response['error'] ) {
      $lastError = $response['error'];
    } else {
      if ( $response['result'] ) {
        header("Location: $redirect");
        exit;
      }
    }
  }
}

///////////////////////////////////////////////////////////////////////////////
// HTTP GET (CONTINUED -- TEMPLATES)
///////////////////////////////////////////////////////////////////////////////

// Non-web clients
if ( $extclient ) {
  header('Content-Type: application/json; charset=utf-8');
  print json_encode(Array(
    'error'   => $lastError,
    'result'  => Array(
      'session' => $_SESSION['user'],
      'page'    => $page
    )
  ));
  exit;
}

$tplTitle = isset($titles[$page]) ? $titles[$page] : $titles['/'];
$tplPages = Array();

$tplPages['/settings'] = <<<EOHTML

        <div id="Body_Settings">

          <h2>Settings</h2>
          <p>
            User settings and vault configuration
          </p>
          <form action="/settings" method="post">
            <div>
              <input type="submit" name="settings" />
            </div>
          </form>

        </div>

EOHTML;

$tplPages['/recover'] = <<<EOHTML

        <div id="Body_Recover">
          <h2>Recover Password</h2>
          <form action="/recover" method="post">
            <div>
              <label for="recoverEmail">E-mail</label>
              <input type="text" name="email" id="recoverEmail" value="" />
            </div>
            <div>
              <input type="submit" name="recover" />
            </div>
          </form>
        </div>

EOHTML;

$tplPages['/login'] = <<<EOHTML

        <div id="Body_Login">
          <h2>Login</h2>
          <form action="/login" method="post">
            <div>
              <label for="loginUsername">Username</label>
              <input type="text" name="username" id="loginUsername" value="" />
            </div>
            <div>
              <label for="loginPassword">Password</label>
              <input type="password" name="password" id="loginPassword" value="" />
            </div>
            <div>
              <input type="submit" name="login" />
              <a href="/recover">Recover password</a>
            </div>
          </form>
        </div>

EOHTML;

$tplPages['/register'] = <<<EOHTML

        <div id="Body_Register">
          <h2>Register</h2>
          <p>
            <i>No e-mail validation is implemented. You will be automatically logged in after registering</i>
          </p>
          <form action="/register" method="post">
            <div>
              <label for="loginUsername">Username</label>
              <input type="text" name="username" id="loginUsername" value="" />
            </div>
            <div>
              <label for="loginEmail">E-mail</label>
              <input type="text" name="email" id="loginEmail" value="" />
            </div>
            <div>
              <label for="loginPassword">Password</label>
              <input type="password" name="password" id="loginPassword" value="" />
            </div>
            <div>
              <input type="submit" name="register" />
            </div>
          </form>
        </div>

EOHTML;

$tplPages['/'] = <<<EOHTML
        <div id="Submenu">
          <a id="CreateEntry" href="javascript:void(0);" class="Disabled">Create Entry</a>
          <a id="SearchEntry" href="javascript:void(0);">Search</a>
        </div>

        <div id="Loading"><img alt="Loading" src="loading.gif" /></div>

        <div id="Body_Categories">
          <div>
            <span><b>Categories:</b></span>
            <ul id="Categories">
              <li><i>Loading...</i></li>
            </ul>
          </div>

          <ul id="List">
          </ul>
        </div>

        <div id="Body_Form" style="display:none;">
          <hr />

          <h2>Entry</h2>
          <form method="post" id="Form" class="">
            <input type="hidden" name="id" id="inpId" value="" />
            <input type="hidden" name="_token" id="inpToken" value="" />
            <div>
              <label for="inpTitle">Title <span class="required">*</span></label>
              <input type="text" name="title" id="inpTitle" value="" />
            </div>
            <div>
              <label for="inpDescription">Description</label>
              <textarea name="description" id="inpDescription"></textarea>
            </div>
            <div>
              <label for="inpCategory">Category <span class="required">*</span></label>
              <input type="text" name="category" id="inpCategory" value="" />
            </div>
            <div>
              <label for="inpPassphrase">Passphrase <span class="required">*</span> <span id="resGeneratePassphrase"></span></label>
              <input type="password" name="passphrase" id="inpPassphrase" value="" class="Yellow" />
            </div>

            <div class="Data">
              <ul>
                <li>
                  <label for="inpEntryURL">URL</label>
                  <input type="text" name="entry_url" id="inpEntryURL" class="Green" />
                </li>
                <li>
                  <label for="inpEntryNotes">Notes</label>
                  <textarea name="entry_notes" id="inpEntryNotes" class="Green"></textarea>
                </li>
                <li>
                  <label for="inpEntryLogin">Login</label>
                  <input type="text" name="entry_login" id="inpEntryLogin" class="Green" />
                </li>
                <li>
                  <label for="inpEntryPassword">Password</label>
                  <input type="text" name="entry_password" id="inpEntryPassword" class="Green" />
                </li>
              </ul>
            </div>

            <div class="UserPermissions" style="display:none">
              <h3>User Permissions (Experimental)</h3>
              <ul id="UserPermissionsTable">
              </ul>
              <ul style="display:none;" id="UserPermissionsTemplate">
                <li>
                  <input name="entry_users[]" placeholder="Enter username here" />
                  <button>Remove</button>
                </li>
              </ul>
              <button id="inpEditPermissionAdd">Add</button>
            </div>

            <div class="Bottom">
              <input type="submit" id="inpSubmit" />
              <button id="inpEditPermissions">User Permissions</button>
              <button id="btnGeneratePassphrase">Generate passphrase</button>
            </div>

            <div class="Timestamps">
              <p>
                <i>Created on: </i><span class="CreatedOn"></span>
              </p>
              <p>
                <i>Edited on: </i><span class="EditedOn"></span>
              </p>
            </div>
          </form>
        </div>

EOHTML;

$tplPage = isset($tplPages[$page]) ? $tplPages[$page] : "<!-- No content -->";

$jsFreeVault = Array(
  'session' => $_SESSION['user'],
  'error'   => $lastError,
  'page'    => $page
);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<!--
  FreeVault (c) 2014 Copyleft Software AS
-->
<html>
  <head>
    <meta charset="utf-8" />
    <title>FreeVault - <?php print htmlspecialchars($tplTitle); ?></title>
    <link type="text/css" rel="stylesheet" href="styles.css" />
    <script type="text/javascript">
      window.FreeVault = window.FreeVault || {};
      FreeVault.Session = <?php print json_encode($jsFreeVault); ?>;
    </script>
    <script charset="utf-8" type="text/javascript" src="jsgenphrase/jsgenphrase-default-wordlist.js"></script>
    <script charset="utf-8" type="text/javascript" src="jsgenphrase/jsgenphrase.js"></script>
    <script charset="utf-8" type="text/javascript" src="utils.js"></script>
    <script charset="utf-8" type="text/javascript" src="main.js"></script>
  </head>
  <body>
    <div id="Blackout" style="display:<?php print $lastError ? 'block' : 'none' ?>;">&nbsp;</div>
    <div id="Head">
      <div class="Wrapper">
        <h1>FreeVault</h1>
        <ul>
<?php if ( empty($_SESSION['user']) ) { ?>
          <li><a id="LoginLink" href="/login">Login</a></li>
          <li><a id="RegisterLink" href="/register">Register</a></li>
<?php } else { ?>
          <li><a href="/">My Vault</a></li>
          <li><a href="/settings">Settings</a></li>
          <li><a id="LogoutLink" href="/logout">Logout</a></li>
<?php } ?>
        </ul>

        <div id="User">
          Logged in as: <span id="UserName"><?php print (empty($_SESSION['user']) ? 'Not logged in' : $_SESSION['user']->username); ?></span>
        </div>
      </div>
    </div>
    <div id="Body">
      <div class="Wrapper">
        <noscript class="CompabilityWarning">You need JavaScript to use FreeVault</noscript>
<?php if ( preg_match('/(?i)msie [2-7]/', $_SERVER['HTTP_USER_AGENT']) ) { ?>

        <div class="CompabilityWarning">Your browser is not supported. Please upgrade!</div>

<?php } ?>

        <div id="ErrorMessage" style="display:<?php print $lastError ? 'block' : 'none' ?>;">
          <h3>An error occured</h3>
          <p><?php print $lastError ? htmlspecialchars($lastError) : '&nbsp;'; ?></p>
          <div id="CloseErrorMessage">X</div>
        </div>
<?php

print $tplPage;

?>
      </div>
    </div>
    <div id="Foot">
      <div class="Wrapper">
        <p>FreeVault &copy; 2014 Copyleft Software AS</p>
      </div>
    </div>
  </body>
</html>
