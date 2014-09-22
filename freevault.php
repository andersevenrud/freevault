<?php
/*!
 * FreeVault (c) Copyleft Software AS
 */

$rootdir = dirname(__FILE__);

// Create this file to override configuration constants below
$cfg = sprintf("%s/config.php", $rootdir);
if ( file_exists($cfg) ) {
  require $cfg;
}

// This file comes from "Composer" (See INSTALL.md)
require 'vendor/autoload.php';

///////////////////////////////////////////////////////////////////////////////
// CONFIGURATION
///////////////////////////////////////////////////////////////////////////////

/**
 * @constant What timezone to use for date/time functions
 */
if ( !defined("FREEVAULT_TIMEZONE")  )
       define("FREEVAULT_TIMEZONE",    "Europe/Oslo");

/**
 * @constant Enable debug mode? (Extra error reporting etc.)
 */
if ( !defined("FREEVAULT_DEBUGMODE") )
       define("FREEVAULT_DEBUGMODE",   false);




/**
 * @constant [ElasticSearch] What host to connect to
 */
if ( !defined("FREEVAULT_ES_HOST") )
       define("FREEVAULT_ES_HOST",        "localhost:9200");

/**
 * @constant [ElasticSearch] What username to use for connection host above
 */
if ( !defined("FREEVAULT_ES_USERNAME") )
       define("FREEVAULT_ES_USERNAME",    "");

/**
 * @constant [ElasticSearch] What password to user for user above
 */
if ( !defined("FREEVAULT_ES_PASSWORD") )
       define("FREEVAULT_ES_PASSWORD",    "");

/**
 * @constant [ElasticSearch] What type of authentication method to user for above login
 */
if ( !defined("FREEVAULT_ES_AUTH") )
       define("FREEVAULT_ES_AUTH",        "Basic");

/**
 * @constant [ElasticSearch] Enable logging (Default on when in debug mode)
 */
if ( !defined("FREEVAULT_ES_LOGGING") )
      define("FREEVAULT_ES_LOGGING",    false);

/**
 * @constant [ElasticSearch] Path to ElasticSearch log file
 */
if ( !defined("FREEVAULT_ES_LOGFILE") )
      define("FREEVAULT_ES_LOGFILE",  sprintf("%s/logs/elasticsearch", $rootdir));




/**
 * @constant [Authentication] Server DSN (PDO)
 */
if ( !defined("FREEVAULT_AUTH_DSN") )
      define("FREEVAULT_AUTH_DSN",        "mysql:host=localhost;dbname=freevault");

/**
 * @constant [Authentication] Server username (PDO)
 */
if ( !defined("FREEVAULT_AUTH_USERNAME") )
      define("FREEVAULT_AUTH_USERNAME",   "freevault");

/**
 * @constant [Authentication] Server password (PDO)
 */
if ( !defined("FREEVAULT_AUTH_PASSWORD") )
      define("FREEVAULT_AUTH_PASSWORD",   "freevault");




/**
 * @constant [VaultLogger] Path to logfile
 */
if ( !defined("FREEVAULT_LOGFILE") )
      define("FREEVAULT_LOGFILE",     sprintf("%s/logs/freevault", $rootdir));

/**
 * @contant [VaultLogger] Loglevel (-1 = all)
 */
if ( !defined("FREEVAULT_LOGLEVEL") )
      define("FREEVAULT_LOGLEVEL",    -1);




/**
 * @constant [Session] Timeout Length
 */
if ( !defined("FREEVAULT_SESS_TIMEOUT") )
      define("FREEVAULT_SESS_TIMEOUT",  (60 * 60) * 2);



/**
 * @constant [Mailer] Mailer enabled
 */
if ( !defined("FREEVAULT_MAIL_ENABLED") )
      define("FREEVAULT_MAIL_ENABLED",     false);

/**
 * @constant [Mailer] Mailer host connection (can be separated by ;)
 */
if ( !defined("FREEVAULT_MAIL_SERVER") )
      define("FREEVAULT_MAIL_SERVER",     "localhost");

/**
 * @constant [Mailer] Mailer host SMTP support?
 *           "" = No SMTP
 */
if ( !defined("FREEVAULT_MAIL_SMTP") )
      define("FREEVAULT_MAIL_SMTP",       "tls");

/**
 * @constant [Mailer] Mailer host username
 */
if ( !defined("FREEVAULT_MAIL_USERNAME") )
      define("FREEVAULT_MAIL_USERNAME",   "");

/**
 * @constant [Mailer] Mailer host password
 */
if ( !defined("FREEVAULT_MAIL_PASSWORD") )
      define("FREEVAULT_MAIL_PASSWORD",   "");

/**
 * @constant [Mailer] Mailer e-mail from field (e-mail address)
 */
if ( !defined("FREEVAULT_MAIL_FROM") )
      define("FREEVAULT_MAIL_FROM",       "no-reply@freevault.localhost");

/**
 * @constant [Mailer] Mailer e-mail from name (full name)
 */
if ( !defined("FREEVAULT_MAIL_FROM_NAME") )
      define("FREEVAULT_MAIL_FROM_NAME",  "FreeVault");

///////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
///////////////////////////////////////////////////////////////////////////////

/**
 * Check if is associative array
 * @return bool
 */
function is_assoc($array) {
  return (bool)count(array_filter(array_keys($array), 'is_string'));
}

///////////////////////////////////////////////////////////////////////////////
// CLASSES
///////////////////////////////////////////////////////////////////////////////

/**
 * ElasticCloud Client Wrapper
 * @link https://github.com/elasticsearch/elasticsearch-php
 * @package FreeVault
 */
class Elastic
{
  protected static $_instance = null;
  private $client = null;

  /**
   * @throws Exception
   */
  protected function __construct() {
    VaultLogger::log("Creating ElasticSearch connection: " . FREEVAULT_ES_HOST, VaultLogger::LEVEL_DEBUG);

    $args = Array('hosts' => Array(FREEVAULT_ES_HOST));
    if ( FREEVAULT_ES_USERNAME ) {
      $args['connectionParams'] = Array(
        'auth' => Array(FREEVAULT_ES_USERNAME, FREEVAULT_ES_PASSWORD, FREEVAULT_ES_AUTH)
      );
    }
    if ( FREEVAULT_ES_LOGGING ) {
      $args['logging'] = true;
      $args['logPath'] = FREEVAULT_ES_LOGFILE;
    }

    if ( !($this->client = new Elasticsearch\Client($args)) ) {
      throw new Exception("Failed to initialize ElasticSearch client");
    }
  }

  /**
   * You can call the Elasticsearch\Client methods directly from this object
   * if the method exists. Otherwise try to call method from this class.
   */
  public function __call($f, Array $args) {
    if ( method_exists($this, $f) ) {
      return call_user_func_array(Array($this, $f), $args);
    }
    return call_user_func_array(Array($this->client, $f), $args);
  }

  /**
   * Get (singleton) instance
   */
  public static function instance() {
    if ( !self::$_instance ) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }
}

/**
 * Database class
 * @package FreeVault
 */
class DB
{
  protected static $db;

  /**
   * Get (singleton) instance
   * @throws PDOException
   */
  public static function get() {
    if ( !self::$db ) {
      VaultLogger::log("Creating Authentication PDO connection: " . FREEVAULT_AUTH_DSN, VaultLogger::LEVEL_DEBUG);

      $args = Array(1002 => "SET NAMES 'utf8'");
      //$args = Array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'");
      if ( $db = new PDO(FREEVAULT_AUTH_DSN, FREEVAULT_AUTH_USERNAME, FREEVAULT_AUTH_PASSWORD, $args) ) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$db = $db;
      }
    }

    return self::$db;
  }

  public static function instance() {
    return self::get();
  }
}

/**
 * Mailer class
 * @package Freevault
 */
class Mailer
{
  /**
   * Send a mail to a specific address
   * @throws Exception
   */
  public static function Send($to, $subject, $bodyHTML, $bodyPlain = null) {
    if ( !FREEVAULT_MAIL_ENABLED ) {
      VaultLogger::log("Mailer is not enabled... skipping", VaultLogger::LEVEL_DEBUG);
      return;
    }

    $mail = new PHPMailer();
    if ( FREEVAULT_MAIL_SMTP ) {
      $mail->isSMTP();
      $mail->SMTPAuth = true;
      $mail->SMTPSecure = FREEVAULT_MAIL_SMTP;
    }

    $mail->Host     = FREEVAULT_MAIL_SERVER;
    $mail->Username = FREEVAULT_MAIL_USERNAME;
    $mail->Password = FREEVAULT_MAIL_PASSWORD;
    $mail->From     = FREEVAULT_MAIL_FROM;
    $mail->FromName = FREEVAULT_MAIL_FROM_NAME;

    if ( is_array($to) ) {
      if ( is_assoc($to) ) {
        foreach ( $to as $k => $v ) {
          VaultLogger::log(sprintf("Sending mail to: %s - %s", $k, $subject), VaultLogger::LEVEL_INFO);
          $mail->addAddress($k, $v);
        }
      } else {
        foreach ( $to as $m ) {
          VaultLogger::log(sprintf("Sending mail to: %s - %s", $m[0], $subject), VaultLogger::LEVEL_INFO);
          $mail->addAddress($m[0], $m[1]);
        }
      }
    } else {
      VaultLogger::log(sprintf("Sending mail to: %s - %s", $to, $subject), VaultLogger::LEVEL_INFO);
      $mail->addAddress($to);
    }

    $mail->WordWrap = 80;
    $mail->Subject = $subject;
    if ( $bodyHTML !== null ) {
      $mail->isHTML(true);
      $mail->Body    = $bodyHTML;
    }
    if ( $bodyPlain !== null ) {
      $mail->AltBody = $bodyPlain;
    }

    if ( !$mail->send() && ($error = $mail->ErrorInfo) ) {
      VaultLogger::log(sprintf("Mailer error: %s", $error), VaultLogger::LEVEL_ERROR);
      throw new Exception("PHPMailer Error: {$error}");
    }
  }
}

/**
 * Logging Class
 * @package FreeVault
 */
class VaultLogger
{
  const LEVEL_INFO  = 0;
  const LEVEL_WARN  = 1;
  const LEVEL_DEBUG = 2;
  const LEVEL_ERROR = 3;
  const LEVEL_PHP   = 4;

  protected static $instance;
  protected $fp;

  protected static $levels = Array('Info', 'Warn', 'Debug', 'Error', 'PHP');

  protected function __construct() {
    $this->fp = fopen(FREEVAULT_LOGFILE, 'wa+');
  }

  public function __destruct() {
    if ( $this->fp ) {
      fclose($this->fp);
    }
  }

  public function logMessage($msg, $level = self::LEVEL_INFO) {
    if ( !$this->fp ) return;
    if ( !$msg ) return;

    $level  = (int) $level;
    if ( !isset(self::$levels[$level]) ) $level = self::LEVEL_INFO;

    if ( $level >= self::LEVEL_ERROR || FREEVAULT_LOGLEVEL == -1 || FREEVAULT_LOGLEVEL >= $level ) {
      $line = sprintf("%s [%s]: %s\n", date("c"), self::$levels[$level], $msg);
      fwrite($this->fp, $line);
    }
  }

  public static function log($msg, $level = self::LEVEL_INFO) {
    self::get()->logMessage($msg, $level);
  }

  public static function get() {
    if ( !self::$instance ) {
      self::$instance = new self();
    }
    return self::$instance;
  }
}

/**
 * Vault User
 * @package FreeVault
 */
class VaultUser
{
  public $id        = 0;
  public $username  = '';
  public $email     = '';

  public function __construct($id, $username, $email) {
    $this->id       = $id;
    $this->username = $username;
    $this->email    = $email;
  }

  /**
   * Logs in with given credentials
   * @throws PDOException
   * @return bool
   */
  public static function Login($username, $password) {
    VaultLogger::log("{$username} requested Login()", VaultLogger::LEVEL_INFO);

    $ip  = empty($_SERVER['REMOTE_ADDR']) ? "127.0.0.1" : $_SERVER['REMOTE_ADDR'];
    $now = time();

    /*
    // DEMO Usage
    $id = 0;
    for ( $i = 0; $i < strlen($username); $i++ ) {
      $id += ord(substr($username, $i));
    }
    $_SESSION['user'] = new self($id, $username, "{$username}@inter.net");
    $_SESSION['time'] = time();
    $_SESSION['ip']   = $ip;

    return true;
    */

    list($phpassHash, $passwordHash) = self::_PasswordHash($password);
    $q = "SELECT `id`, `username`, `email`, `password` FROM `users` WHERE `username` = ? LIMIT 1;";
    $a = Array($username);

    $result = null;
    if ( $stmt = DB::get()->prepare($q) ) {
      $stmt->setFetchMode(PDO::FETCH_ASSOC);
      if ( $stmt->execute($a) ) {
        if ( $row = $stmt->fetch() ) {
          if ( $phpassHash->checkPassword($password, $row['password']) ) {
            $result = $row;
          }
        }
      }
    }

    if ( $result ) {
      $q = "UPDATE `users` SET `last_login` = ?, `last_ip` = ? WHERE `id` = ?;";
      $a = Array($now, $ip, $result['id']);
      if ( $stmt = DB::get()->prepare($q) ) {
        if ( $stmt->execute($a) ) {
          $_SESSION['user'] = new self($result['id'], $result['username'], $result['email']);
          $_SESSION['time'] = $now;
          $_SESSION['ip']   = $ip;
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Check if given session is still valid
   * @return bool
   */
  public static function CheckSession() {
    if ( !empty($_SESSION['user']) && ($user = $_SESSION['user']) ) {
      if ( !isset($_SESSION['time']) ) {
        $_SESSION['time'] = time();
      }
      $ip = empty($_SERVER['REMOTE_ADDR']) ? "127.0.0.1" : $_SERVER['REMOTE_ADDR'];
      if ( !isset($_SESSION['ip']) ) {
        $_SESSION['ip'] = $ip;
      }

      $diff = time() - ((int) $_SESSION['time']);
      if ( $diff > FREEVAULT_SESS_TIMEOUT ) {
        VaultLogger::log("{$user->username} session expired!", VaultLogger::LEVEL_INFO);
        self::Logout();
        return false;
      }

      if ( $_SESSION['ip'] != $ip ) {
        VaultLogger::log("{$user->username} session remoteaddr changed!", VaultLogger::LEVEL_INFO);
        self::Logout();
        return false;
      }
    }
    return true;
  }

  /**
   * Logs out the current user
   * @return bool
   */
  public static function Logout() {
    $username = empty($_SESSION['user']) ? '<empty>' : $_SESSION['user']->username;
    VaultLogger::log("{$username} requested Logout()", VaultLogger::LEVEL_INFO);

    // FIXME: Should the db be updated with something here !?
    unset($_SESSION['user']);
    return true;
  }

  /**
   * Recover user password
   * @throws Exception
   * @return bool
   */
  public static function Recover($email, $confirm = null) {
    VaultLogger::log("{$email} requested Recover()", VaultLogger::LEVEL_INFO);
    throw new Exception("Not implemented"); // TODO
    $mail = <<<EOHTML

You have requested a password reset. Follow this link to complete your request:<br />
<a href="about:blank">something will come here</a>

EOHTML;
    Mailer::Send($email, "Password Recovery", $mail);
    return false;
  }

  /**
   * Register user
   * @throws Exception
   * @throws PDOException
   * @return bool
   */
  public static function Register($username, $password, $email) {
    VaultLogger::log("{$username} ({$email}) requested Register()", VaultLogger::LEVEL_INFO);

    if ( $stmt = DB::get()->prepare("SELECT 1 FROM `users` WHERE `username` = ? LIMIT 1;") ) {
      if ( $stmt->execute(Array($username)) && $stmt->fetch() ) {
        throw new Exception("This username is already taken");
      }
    }

    if ( $stmt = DB::get()->prepare("SELECT 1 FROM `users` WHERE `email` = ? LIMIT 1;") ) {
      if ( $stmt->execute(Array($email)) && $stmt->fetch() ) {
        throw new Exception("An account was already registered with this email-address");
      }
    }

    list($phpassHash, $passwordHash) = self::_PasswordHash($password);
    $q = "INSERT INTO `users` (`username`, `email`, `password`) VALUES(?, ?, ?);";
    $a = Array($username, $email, $passwordHash);
    if ( $stmt = DB::get()->prepare($q) ) {
      if ( $stmt->execute($a) ) {

        $mail = <<<EOHTML

Before you can log in you have to follow this link to activate your account:<br />
<a href="about:blank">something will come here</a>

EOHTML;
        Mailer::Send($email, "Account Registration", $mail);


        return true;
      }
    }

    return false;
  }

  /**
   * Password Hashing method
   * @return list
   */
  protected static function _PasswordHash($password) {
    $adapter = new \Phpass\Hash\Adapter\Pbkdf2(array (
      'iterationCount' => 15000
    ));
    $phpassHash = new \Phpass\Hash($adapter);
    return Array($phpassHash, $phpassHash->hashPassword($password));
  }
}

/**
 * Vault Entry
 * @package FreeVault
 */
class VaultEntry
{
  public $id            = ''; // Only used internally

  // Fields used for storage
  public $user_id       = -1;
  public $title         = '';
  public $description   = '';
  public $category      = '';
  public $encoded       = '';
  public $allowed_users = Array();
  public $created_on    = null;
  public $edited_on     = null;

  public function __construct(Array $fields, $format = false) {
    foreach ( $fields as $k => $v ) {
      $this->__set($k, $v);
    }

    if ( $format ) {
      if ( $this->created_on ) {
        $this->created_on = date('r', $this->created_on);
      }
      if ( $this->edited_on ) {
        $this->edited_on = date('r', $this->edited_on);
      }
    }
  }

  // Throw exceptions instead of default PHP warnings
  public function __set($k, $v) {
    if ( !property_exists($this, $k) ) {
      throw new Exception("VaultEntry has no property (set) '{$k}'");
    }
    $this->$k = $v;
  }

  // Throw exceptions instead of default PHP warnings
  public function __get($k) {
    if ( !property_exists($this, $k) ) {
      throw new Exception("VaultEntry has no property (get) '{$k}'");
    }
    return $this->$k;
  }

  /**
   * Get data for storage
   * @return  Array
   */
  public function toArray() {
    return Array(
      'user_id'       => (int)    $this->user_id,
      'title'         => (string) $this->title,
      'description'   => (string) $this->description,
      'category'      => (string) $this->category,
      'encoded'       => (string) $this->encoded,
      'allowed_users' => (array)  $this->allowed_users,
      'created_on'    => (int)    $this->created_on,
      'edited_on'     => (int)    $this->edited_on
    );
  }

  /**
   * Create parameters for a ElasticSearch API call
   * @return Array
   */
  public static function CreateParams(Array $combine, $refresh = false) {
    $defaults = Array(
      'index' => 'entries',
      'type'  => 'entry'
    );
    if ( $refresh ) $defaults['refresh'] = true;

    return array_merge($defaults, $combine);
  }

  /**
   * Create new entry
   *
   * @param   Array       $fields       Entry Data
   * @param   VaultUser   $user         User instance
   * @return  boolean
   */
  public static function CreateEntry(Array $fields, VaultUser $user) {
    VaultLogger::log("{$user->username} requested CreateEntry()", VaultLogger::LEVEL_INFO);

    unset($fields['id']);

    $fields['user_id'] = $user->id;
    $entry = new VaultEntry($fields);
    $entry->created_on = time();

    $params = self::CreateParams(Array(
      'body' => $entry->toArray()
    ), true);

    if ( $result = Elastic::instance()->index($params) ) {
      return $result["created"] === true;
    }
    return false;
  }

  /**
   * Edit existing entry
   *
   * @param   String      $id           Entry ID
   * @param   Array       $fields       Entry Data
   * @param   VaultUser   $user         User instance
   * @return  boolean
   */
  public static function EditEntry($id, Array $fields, VaultUser $user) {
    VaultLogger::log("{$user->username} requested EditEntry({$id})", VaultLogger::LEVEL_INFO);

    unset($fields['id']);
    $fields['user_id'] = $user->id;

    if ( $old = self::GetEntry($id, $user) ) {
      $new = new VaultEntry($fields);
      $new->created_on = $old->created_on;
      $new->edited_on  = time();

      $params = self::CreateParams(Array(
        'id'   => $id,
        'body' => Array('doc' => $new->toArray())
      ), true);

      if ( $result = Elastic::instance()->update($params) ) {
        //return $result["ok"] === true;
        return true;
      }
    }
    return false;
  }

  /**
   * Delete existing entry
   *
   * @param   String      $id           Entry ID
   * @param   VaultUser   $user         User instance
   * @return  boolean
   */
  public static function DeleteEntry($id, VaultUser $user) {
    VaultLogger::log("{$user->username} requested DeleteEntry({$id})", VaultLogger::LEVEL_INFO);

    if ( self::GetEntry($id, $user) ) {
      $params = self::CreateParams(Array(
        'id' => $id
      ), true);

      if ( $result = Elastic::instance()->delete($params) ) {
        return true;
        //return $result["ok"] === true;
      }
    }
    return false;
  }

  /**
   * Wrapper for GetEntries()
   */
  protected static function _GetEntries(Array $query, VaultUser $user, Array $whatFields) {
    $params = self::CreateParams(Array(
      'body' => Array('query' => $query)
    ));

    if ( $whatFields ) {
      $params['_source_include'] = implode(',', $whatFields);
    }

    $list = Array();
    if ( $ret = Elastic::instance()->search($params) ) {
      if ( isset($ret['hits']) ) {
        foreach ( $ret['hits']['hits'] as $i ) {
          $data       = $i['_source'];
          $data['id'] = $i['_id'];

          $list[] = new VaultEntry($data, true);
        }
      }
    }

    return $list;
  }

  /**
   * Get a list of entries
   *
   * FIXME: This can certainly be optimized
   *
   * @param   String      $cat          Category name (optional)
   * @param   VaultUser   $user         User instance
   * @param   Array       $whatFields   Customize what fields to return (optional)
   * @return  Array                     List of VaultEntry
   */
  public static function GetEntries($cat = null, VaultUser $user, Array $whatFields = Array()) {
    VaultLogger::log("{$user->username} requested GetEntries({$cat})", VaultLogger::LEVEL_DEBUG);

    // First get user entries
    $search = Array();
    if ( $cat ) {
      $search[] = Array("match" => Array("category" => $cat));
    }
    $search[] = Array("match" => Array("user_id" => $user->id));

    $list = self::_GetEntries(Array('bool' => Array('must' => $search)), $user, $whatFields);


    // Then get shared entries
    $search = Array(
      Array("match" => Array("allowed_users" => $user->username))
    );
    if ( $cat ) {
      $search[] = Array("match" => Array("category" => $cat));
    }

    if ( $shared = self::_GetEntries(Array('bool' => Array('must' => $search)), $user, $whatFields) ) {
      $list = array_merge($list, $shared);
    }

    return $list;
  }

  /**
   * Get entries by search query
   *
   * @param   String      $cat          Search in category (can be null)
   * @param   String      $query        Search query
   * @param   VaultUser   $user         User instance
   * @return  Array                     A list of matching VaultEntry
   */
  public static function GetEntriesSearch($cat, $query, VaultUser $user) {
    $search = Array(
      'query_string' => Array(
        'query' => $query
      )
    );
    $list = self::_GetEntries($search, $user, Array());
    return $list;
  }

  /**
   * Get entry by id
   *
   * @param   String      $id           Entry ID
   * @param   VaultUser   $user         User instance
   * @return  VaultEntry
   */
  public static function GetEntry($id, VaultUser $user) {
    VaultLogger::log("{$user->username} requested GetEntry({$id})", VaultLogger::LEVEL_DEBUG);

    $params = self::CreateParams(Array(
      'id' => $id
    ));

    if ( $ret = Elastic::instance()->get($params) ) {
      if ( isset($ret['_source']) ) {
        $data = $ret['_source'];
        $data['id'] = $ret['_id'];

        // FIXME: Not the correct way to do this -- create a custom query
        $allowed = isset($data['allowed_users']) ? $data['allowed_users'] : Array();
        if ( ((int)$data['user_id']) === ((int)$user->id) || (in_array($user->username, $allowed)) ) {
          return new VaultEntry($data, true);
        }
      }
    }

    return false;
  }

  /**
   * Get a list of categories
   *
   * @param   VaultUser   $user         User instance
   * @return  Array                     List of categories
   */
  public static function GetCategories(VaultUser $user) {
    VaultLogger::log("{$user->username} requested GetCategories()", VaultLogger::LEVEL_DEBUG);

    $list = Array();
    if ( $res = self::GetEntries(null, $user, Array('category')) ) {
      foreach ( $res as $i ) {
        // Counter used for frontend
        if ( !isset($list[$i->category]) ) {
          $list[$i->category] = 1;
        } else {
          $list[$i->category]++;
        }
      }
    }
    return $list;
  }

}

///////////////////////////////////////////////////////////////////////////////
// BOOTSTRAP
///////////////////////////////////////////////////////////////////////////////

// Error reporting
if ( FREEVAULT_DEBUGMODE ) {
  error_reporting(E_ALL);
} else {
  error_reporting(-1);
}

// Date & Time
date_default_timezone_set(FREEVAULT_TIMEZONE);

// ElasticSearch client
try {
  if ( !Elastic::instance() ) {
    die("Fatal error on creating ElasticSearch instance");
  }
} catch ( Exception $e ) {
  die("Failed to initialize ElasticSearch: {$e->getMessage()}");
}

// Database
try {
  if ( !DB::instance() ) {
    die("Fatal error on creating DB instance");
  }
} catch ( Exception $e ) {
  die("Failed to initialize DB: {$e->getMessage()}");
}

?>
