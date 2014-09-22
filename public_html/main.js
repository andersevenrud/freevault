/*!
 * FreeVault (c) 2014 Copyleft Software AS
 */
"use strict";

(function() {
  window.FreeVault  = window.FreeVault  || {};
  FreeVault.Config  = FreeVault.Config  || {};
  FreeVault.Utils   = FreeVault.Utils   || {};
  FreeVault.Session = FreeVault.Session || {};

  // TODO: Dynamically set up entry data stuff from PHP (reflection) ?!

  /////////////////////////////////////////////////////////////////////////////
  // GLOBALS
  /////////////////////////////////////////////////////////////////////////////

  // FIXME: Document how to override settings (override is in window.onload)

  var _API                  = '/';      // API Url (POST)
  var _EncryptionMethod     = 'AES';    // Current encryption method (default)

  var _AutocompleteTimeout  = 100;      // Autocomplete for category timeout
  var _PollTimeout          = 10;       // Delay-time for the async calls

  var _Storage              = null;     // Current EntryStorage instance
  var _Category             = null;     // Current selected category name
  var _LastCategoryList     = [];       // Current list of categories
  var _LastEntryCount       = 0;        // Current count of entries (total)
  var _$LoadingIndicator    = null;     // Loading indicator on AJAX
  var _UserId               = 0;

  /////////////////////////////////////////////////////////////////////////////
  // HELPERS
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Entry Storage
   */
  var EntryStorage = function() {
  };

  EntryStorage.prototype._post = function(args, callback) {
    _$LoadingIndicator.style.display = 'block';

    FreeVault.Utils.AjaxPost(_API, args, function(response) {
      _$LoadingIndicator.style.display = 'none';

      if ( typeof response === 'string' && response.match(/^\{|\[/) ) {
        try {
          response = JSON.parse(response);
        } catch ( e ) {
          response = {result: false, error: 'Failed to parse JSON: ' + e};
        }
      }

      if ( response && response.result ) {
        callback(response.result);
      } else {
        callback(false, response.error || 'Unknown error');
      }
    }, function(err) {
      callback(false, ('HTTP 500 - ' + (err || 'Fatal server error')));

      _$LoadingIndicator.style.display = 'none';
    });
  };

  EntryStorage.prototype.getCategories = function(callback) {
    console.debug("EntryStorage::getCategories()");

    var self = this;
    this._post({'action': 'categories'}, function(result, error) {
      callback.call(self, result, error);
    });
  };

  EntryStorage.prototype.getList = function(category, query, callback) {
    console.debug("EntryStorage::getList()", category, query);

    var self = this;
    if ( query && query.length ) {
      this._post({'action': 'search', 'query': query}, function(result, error) {
        callback.call(self, result, error);
      });
    } else {
      this._post({'action': 'list', 'category': category}, function(result, error) {
        callback.call(self, result, error);
      });
    }
  };

  EntryStorage.prototype.getItem = function(id, callback, requestToken) {
    requestToken = (typeof requestToken !== 'undefined' && requestToken === true);
    console.debug("EntryStorage::getItem()", id, requestToken);

    var self = this;
    this._post({'action': 'get', 'id': id, requestToken: requestToken}, function(result, error) {
      callback.call(self, result, error);
    });
  };

  EntryStorage.prototype.saveItem = function(id, entry, token, callback) {
    id = id || '';
    token = token || '';
    console.debug("EntryStorage::saveItem()", id, entry);
    if ( !(entry instanceof Entry) ) { throw "An entry was expected"; }

    var self = this;
    var args = {};
    args.action = ( id.length ) ? 'edit' : 'create';
    args._token = token;
    if ( args.action === 'edit' ) {
      args.id = id;
    }
    for ( var i in entry ) {
      if ( entry.hasOwnProperty(i) ) {
        args[i] = entry[i];
      }
    }

    this._post(args, function(result, error) {
      callback.call(self, result, error);
    });
  };

  EntryStorage.prototype.removeItem = function(id, callback) {
    console.debug("EntryStorage::removeItem()", id);
    this._post({'action': 'delete', 'id': id}, function(result, error) {
      callback.call(self, result, error);
    });
  };

  /**
   * Entry
   */
  var Entry = function(title, description, category, encoded, allowed_users) {
    this.title          = title         || '';
    this.description    = description   || '';
    this.category       = category      || '';
    this.encoded        = encoded       || '';
    this.allowed_users  = allowed_users || [];

    console.debug('Entry::__construct()', this.title);
  };

  Entry.prototype.encode = function(data, passphrase) {
    data = JSON.stringify(data);

    var str = CryptoJS[_EncryptionMethod].encrypt(data, passphrase).toString();
    return str;
  };

  Entry.prototype.decode = function(passphrase) {
    var data   = this.encoded;
    var str    = CryptoJS[_EncryptionMethod].decrypt(data, passphrase);

    if ( str ) {
      try {
        var result = str.toString(CryptoJS.enc.Utf8);
        return JSON.parse(result);
      } catch ( e ) {
        console.warn("Failed to decode with", passphrase, e);
      }
    }
    return null;
  };

  function EntryFromJSON(jsn) {
    return new Entry(jsn.title, jsn.description, jsn.category, jsn.encoded, jsn.allowed_users);
  }

  /////////////////////////////////////////////////////////////////////////////
  // APPLICATION
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Main initialization function
   */
  function Initialize(failed_init) {
    // Error Message Box
    var _e = document.getElementById("ErrorMessage");
    var _b = document.getElementById("Blackout");
    var _c = document.getElementById("CloseErrorMessage");

    _c.onclick = function(ev) {
      ev.stopPropagation();
      ev.preventDefault();
      _e.style.display = 'none';
      _b.style.display = 'none';
      return false;
    };

    _b.onclick = function(ev) {
      ev.preventDefault();
      //_c.onclick(ev);
      return false;
    };

    window.onkeydown = function(ev) {
      if ( ev.which === 27 ) {
        _c.onclick(ev);
      }
    };

    if ( failed_init ) { return; }

    try {
      _UserId = FreeVault.Session.session.id;
    } catch ( e ) {
      _UserId = -1;
    }

    // Lists and Forms
    if ( FreeVault.Session.page === '/' ) {
      try {
        _Storage = new EntryStorage();
      } catch ( e ) {
        DisplayError("Failed to initialize EntryStorage: " + e);
        return;
      }

      var btn = document.getElementById('CreateEntry');
      if ( btn ) {
        btn.onclick = function() {
          if ( this.className === "disabled" ) { return; }

          try {
            SetupForm('create', {
              title: 'New entry ' + _LastEntryCount,
              category: _Category || ''
            }, false, 'Create Entry');
          } catch ( e ) {
            DisplayError("Failed to initialize form: " + e);
            console.warn("Faield to initialize form", e.stack);
          }
        };

        btn.className = '';
      }

      var btns = document.getElementById('SearchEntry');
      btns.onclick = function() {
        var s = prompt("Your query");
        RenderList(false, false, s);

      };

      RenderList();
    }
  }

  /**
   * Display error message
   */
  function DisplayError(msg) {
    var _e = document.getElementById("ErrorMessage");
    var _b = document.getElementById("Blackout");
    var _t = _e.getElementsByTagName('h3')[0];
    var _d = _e.getElementsByTagName('p')[0];

    _d.innerHTML = '';
    _d.appendChild(document.createTextNode(msg));
    _e.style.display = 'block';
    _b.style.display = 'block';
  }

  /**
   * Load and render the list
   */
  function RenderList(skipCategories, skipEntries, searchQuery) {
    console.log("RenderList()", skipCategories, skipEntries);

    var _render = function() {
      if ( !skipCategories ) {
        _Storage.getCategories(function(result, error) {
          error = error || (result ? null : 'Unknown error');
          if ( error ) {
            DisplayError("Failed to list categories: " + error);
            return;
          }
          FillCategories(_Category, result);
        });
      }

      if ( !skipEntries ) {
        if ( _Category ) {
          _Storage.getList(_Category, searchQuery, function(result, error) {
            error = error || (result ? null : 'Unknown error');
            if ( error ) {
              DisplayError("Failed to list entries: " + error);
              return;
            }
            FillList(result);
          });
        } else {
          var _l = document.getElementById('List');
          if ( _l ) {
            _l.innerHTML = '<li><i>Select a category to view entries</i></li>';
          }
        }
      }
    };

    setTimeout(function() {
      _render();
    }, _PollTimeout);
  }

  /**
   * Fill categories container with given list
   */
  var FillCategories = (function() {

    var _LastCategory = null;

    function ShowCategory(ev, el, id, name) {
      if ( _LastCategory ) {
        _LastCategory.className = '';
        _LastCategory = null;
      }

      _Category = name;
      _LastCategory = el;
      _LastCategory.className = 'Active';

      try {
        var h = _Category ? "#" + _Category : "";
        if ( history.pushState ) {
          history.pushState(null, null, h);
        } else {
          location.hash = h;
        }
      } catch ( e ) {
        console.warn("Failed to update hash", h, e.message, e.stack);
      }

      RenderList(true);
    }

    return function(current, list) {
      console.log("FillCategories()", current);
      _LastCategoryList = [];
      _LastEntryCount = 0;

      var _l = document.getElementById('Categories');
      _l.innerHTML = '';

      var el, span;
      var count = 0;
      for ( var i in list ) {
        if ( list.hasOwnProperty(i) ) {
          _LastCategoryList.push(i);

          el = document.createElement('li');
          span = document.createElement('span');
          span.appendChild(document.createTextNode(i + " (" + list[i] + ")"));

          if ( current === i ) {
            el.className = 'Active';
            _LastCategory = el;
          }

          el.onclick = (function(id, name) {
            return function(ev) {
              ShowCategory(ev, this, id, name);
            };
          })(-1, i);

          el.appendChild(span);

          _l.appendChild(el);

          count++;
          _LastEntryCount += list[i];
        }
      }

      if ( count <= 0 ) {
        el = document.createElement('li');
        span = document.createElement('i');
        span.appendChild(document.createTextNode("No entries yet..."));
        el.appendChild(span);
        _l.appendChild(el);
      }

    };
  })();

  /**
   * Fill entries container with given list
   */
  var FillList = (function() {

    function ShowEntry(id, result, passphrase, disabled) {
      var entry = EntryFromJSON(result);
      if ( entry ) {
        console.info("ShowEntry()", entry);

        var decoded = entry.decode(passphrase);
        if ( decoded ) {
          console.info("ShowEntry()", decoded);

          var dates = {
            created: result.created_on,
            edited: result.edited_on
          };

          SetupForm('edit', {
            id:             id,
            title:          entry.title,
            description:    entry.description,
            category:       entry.category,
            entry_login:    decoded.login,
            entry_url:      decoded.url,
            entry_password: decoded.password,
            entry_notes:    decoded.notes,
            entry_users:    entry.allowed_users
          }, disabled, (disabled ? 'Showing entry' : 'Edit entry'), result._token, dates);
        } else {
          DisplayError("Failed to decode entry! Wrong passphrase?");
        }
      }
    }

    function EditEntry(id, entry, passphrase) {
      _Storage.getItem(id, function(result, error) {
        console.info("EditEntry()", result);

        error = error || (result ? null : 'Unknown error');
        if ( error ) {
          DisplayError("Failed to load the requested entry: " + error);
          return;
        }

        ShowEntry(id, result, passphrase);
        RenderList();
      });
    }

    function DecodeEntry(id, entry, passphrase) {
      _Storage.getItem(id, function(result, error) {
        console.info("DecodeEntry()", result);

        error = error || (result ? null : 'Unknown error');
        if ( error ) {
          DisplayError("Failed to load the requested entry: " + error);
          return;
        }

        ShowEntry(id, result, passphrase, true);
        RenderList();
      });
    }

    function DeleteEntry(id, entry, passphrase) {
      var _remove = function() {
        _Storage.removeItem(id, function(result, error) {
          console.info("DeleteEntry()", result, error);

          error = error || (result ? null : 'Unknown error');
          if ( error ) {
            DisplayErro("Failed to delete the requested entry: " + error);
            return;
          }

          RenderList();
        });
      };

      _Storage.getItem(id, function(result, error) {
        error = error || (result ? null : 'Unknown error');
        if ( error ) {
          DisplayError("Failed to load the requested entry: " + error);
          return;
        }

        var entry = EntryFromJSON(result);
        var valid = false;
        if ( entry ) {
          var decoded = entry.decode(passphrase);
          if ( decoded ) {
            valid = true;
          }
        }

        if ( valid ) {
          if ( !confirm("Are you sure you want to delete this entry?") ) { return; }
          _remove();
        } else {
          DisplayError("Failed to decode entry! Wrong passphrase?");
        }

      });
    }

    return function(entries) {
      var _l = document.getElementById('List');
      var container = document.getElementById('Body_Form');

      _l.innerHTML = '';

      var i = 0, l = entries.length;
      var iter, li, title, desc, bedit, bdec, bdel, ipass, inner, note;
      for ( i; i < l; i++ ) {
        iter = entries[i];
        note = '';
        if ( !iter ) { continue; }

        console.warn(iter, _UserId);
        li = document.createElement('li');
        if ( _UserId != iter.user_id ) {
          note = ' <shared>';
        }

        title = document.createElement('span');
        title.className = 'Title';
        title.appendChild(document.createTextNode(iter.title + note));

        desc = document.createElement('span');
        desc.className = 'Description';
        desc.appendChild(document.createTextNode(iter.description));

        inner = document.createElement('div');

        ipass = document.createElement('input');
        ipass.type = 'password';
        ipass.value = '';

        bdec = document.createElement('button');
        bdec.innerHTML = 'Decrypt';
        bdec.onclick = (function(inp, obj, id) {
          return function(ev) {
            container.style.display = 'none';
            DecodeEntry(id, obj, inp.value);
            inp.value = '';
          };
        })(ipass, iter, iter.id);

        bedit = document.createElement('button');
        bedit.innerHTML = 'Edit';
        bedit.onclick = (function(inp, obj, id) {
          return function(ev) {
            container.style.display = 'none';
            EditEntry(id, obj, inp.value);
            inp.value = '';
          };
        })(ipass, iter, iter.id);

        bdel = document.createElement('button');
        bdel.innerHTML = 'Delete';
        bdel.onclick = (function(inp, obj, id) {
          return function(ev) {
            container.style.display = 'none';
            DeleteEntry(id, obj, inp.value);
            inp.value = '';
          };
        })(ipass, iter, iter.id);

        ipass.onkeypress = (function(btn) {
          return function(ev) {
            var key = ev.which || ev.keyCode;
            if ( key == 13 ) {
              btn.onclick();
            }
          };
        })(bdec);

        inner.appendChild(ipass);
        inner.appendChild(bdec);
        inner.appendChild(bedit);
        inner.appendChild(bdel);

        li.appendChild(title);
        li.appendChild(desc);
        li.appendChild(inner);

        _l.appendChild(li);
      }
    };
  })();

  /**
   * Set-up the entry form
   */
  var SetupForm = (function() {
    var _initialized = false;
    var _inputs      = {};

    var autocomplete = (function() {
      var _timeout;

      var _search = function(ev, input) {
        var orig  = input.value;
        var list  = _LastCategoryList || [];

        if ( !orig.length || !list.length ) { return; }

        var found = null;
        for ( var i = 0; i < list.length; i++ ) {
          if ( list[i].toLowerCase().indexOf(orig.toLowerCase()) >= 0 ) {
            found = list[i];
            break;
          }
        }

        console.debug("SetupForm()->autocomplete()->_search()", orig, '=>', found);

        if ( found && found.length ) {
          input.value = found;
          try {
            FreeVault.Utils.SelectRange(input, orig.length, found.length);
          } catch ( e ) {
            console.warn("Failed to select range", e.message, e.stack);
          }
        }
      };

      return function (ev, input) {
        var kc = (String.fromCharCode(ev.which) || '');
        if ( !kc || kc.match(/[^A-z0-9\s]/) ) { return; }

        if ( _timeout ) {
          clearTimeout(_timeout);
          _timeout = null;
        }

        _timeout = setTimeout(function() {
          _search(ev, input);
        }, _AutocompleteTimeout);
      };
    })();

    function onsubmit(ev, button, form, container, values, callback) {
      button.setAttribute('disabled', 'disabled');

      if ( !values.title )        { throw "You need to fill in 'Title'"; }
      if ( !values.category )     { throw "You need to fill in 'Category'"; }
      if ( !values.passphrase )   { throw "You need to fill in 'Passphrase'"; }

      var token = values._token || '';
      var entry = new Entry(values.title, values.description, values.category, null, values.entry_users);
      entry.encoded = entry.encode({
        login:          values.entry_login,
        url:            values.entry_url,
        password:       values.entry_password,
        notes:          values.entry_notes
      }, values.passphrase);

      var id = (''+values.id).length ? (values.id) : null;

      console.debug("onsubmit()", "values", values);
      console.debug("onsubmit()", "entry", entry);
      console.debug("onsubmit()", "id", id);

      _Storage.saveItem(id, entry, token, function(result, error) {
        error = error || (result ? null : 'Unknown error');
        if ( error ) {
          DisplayError("Something went wrong while saving your entry: " + error);
        } else {
          container.style.display = 'none';
        }
        button.removeAttribute('disabled');

        callback(error ? false : true);
      });

    }

    return function(ftype, values, disabled, title, token, dates) {
      title = title || 'Entry';
      token = token || '';
      dates = dates || {};
      console.log("SetupForm()", values, disabled, title);


      var form = document.getElementById('Form');
      var container = document.getElementById('Body_Form');
      var submit = document.getElementById('inpSubmit');
      var permEdit = document.getElementById('inpEditPermissions');
      var permContainer = container.getElementsByClassName('UserPermissions')[0];
      var permAdd = document.getElementById('inpEditPermissionAdd');
      var permTemplate = document.getElementById('UserPermissionsTemplate').getElementsByTagName('LI')[0];
      var permTable = document.getElementById('UserPermissionsTable');
      var h2 = container.getElementsByTagName('h2')[0];
      var genButton = document.getElementById('btnGeneratePassphrase');
      var genResult = document.getElementById('resGeneratePassphrase');
      var inpToken = document.getElementById('inpToken');
      var timeCreatedOn = container.getElementsByClassName('CreatedOn')[0];
      var timeEditedOn = container.getElementsByClassName('EditedOn')[0];
      var timeContainer = container.getElementsByClassName('Timestamps')[0];

      genButton.onclick = function(ev) {
        ev.preventDefault();
        ev.stopPropagation();

        var result = GenPhrase({
          enableSeparators: false
        });
        genResult.innerHTML = result;
        document.getElementById('inpPassphrase').value = result;

        return false;
      };

      var _createUserEntry = function(val) {
        var cel = permTemplate.cloneNode(true);
        cel.getElementsByTagName('input')[0].value = val || '';
        cel.getElementsByTagName('button')[0].onclick = function(ev) {
          ev.preventDefault();
          ev.stopPropagation();
          this.parentNode.parentNode.removeChild(this.parentNode);
          return false;
        };
        permTable.appendChild(cel);
      };

      permEdit.onclick = function(ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var s = permContainer.style.display;
        permContainer.style.display = (s == '' || s == 'none') ? 'block' : 'none';
        return false;
      };

      permAdd.onclick = function(ev) {
        ev.preventDefault();
        ev.stopPropagation();
        _createUserEntry('');
        return false;
      };

      if ( h2 ) {
        h2.innerHTML = '';
        h2.appendChild(document.createTextNode(title));
      }

      if ( !_initialized ) {
        _inputs = {
          id:             document.getElementById('inpId'),
          title:          document.getElementById('inpTitle'),
          description:    document.getElementById('inpDescription'),
          category:       document.getElementById('inpCategory'),
          entry_login:    document.getElementById('inpEntryLogin'),
          entry_url:      document.getElementById('inpEntryURL'),
          entry_password: document.getElementById('inpEntryPassword'),
          entry_notes:    document.getElementById('inpEntryNotes'),
          passphrase:     document.getElementById('inpPassphrase')
        };

        form.setAttribute("autocomplete", "off");

        form.onsubmit = function(ev) {
          ev.preventDefault();
          if ( submit.getAttribute('disabled') === 'disabled' ) { return; }

          var values = {entry_users: [], _token: inpToken.value};
          for ( var i in _inputs ) {
            if ( _inputs.hasOwnProperty(i) ) {
              if ( !_inputs[i] ) { continue; }

              values[i] = _inputs[i].value;
            }
          }

          var users = permTable.getElementsByTagName('input');
          for ( i = 0; i < users.length; i++ ) {
            if ( users[i].value.length ) {
              values.entry_users.push(users[i].value);
            }
          }

          try {
            onsubmit(ev, submit, form, container, values, function(result) {
              RenderList();
            });
          } catch ( e ) {
            DisplayError("Failed to submit your entry: " + e);

            submit.removeAttribute('disabled');
            console.warn(e.stack);
          }
        };

        if ( _inputs.category ) {
          _inputs.category.onkeyup = function(ev) {
            autocomplete(ev, this);
          };
        }

        _initialized = true;
      }

      // Reset values
      var i;
      for ( i in _inputs ) {
        if ( _inputs.hasOwnProperty(i) ) {
          if ( !_inputs[i] ) { continue; }
          if ( disabled ) {
            _inputs[i].setAttribute('disabled', 'disabled');
          } else {
            _inputs[i].removeAttribute('disabled');
          }
          _inputs[i].value = '';
        }
      }
      permTable.innerHTML = '';

      // Fill items
      if ( typeof values === 'object' ) {
        for ( i in values ) {
          if ( values.hasOwnProperty(i) && _inputs.hasOwnProperty(i) ) {
            if ( !_inputs[i] ) { continue; }
            _inputs[i].value = values[i];
          }
        }
      }

      if ( values.entry_users ) {
        for ( i = 0; i < values.entry_users.length; i++ ) {
          _createUserEntry(values.entry_users[i]);
        }
      }

      timeContainer.style.display = ftype == 'create' ? 'none' : 'block';
      timeCreatedOn.innerHTML = dates.created || '&lt;unknown&gt;';
      timeEditedOn.innerHTML = dates.edited || '&lt;unknown&gt;';

      permContainer.style.display = 'none';
      container.style.display = 'block';
      genResult.innerHTML = '';
      inpToken.value = token;
      if ( disabled ) {
        submit.setAttribute('disabled', 'disabled');
        submit.value = 'Entry is locked';
        permEdit.setAttribute("disabled", "disabled");
        genButton.setAttribute("disabled", "disabled");
        form.className = 'Show';
      } else {
        submit.removeAttribute('disabled');
        submit.value = 'Submit';
        permEdit.removeAttribute("disabled");
        genButton.removeAttribute("disabled");
        form.className = 'Edit';
      }
    };
  })();

  /////////////////////////////////////////////////////////////////////////////
  // MAIN
  /////////////////////////////////////////////////////////////////////////////

  window.onload = function() {
    if ( typeof FreeVault.Config.API !== 'undefined' ) {
      _API = FreeVault.Config.API;
    }
    if ( typeof FreeVault.Config.Encryption !== 'undefined' ) {
      _EncryptionMethod = FreeVault.Config.Encryption;
    }

    _$LoadingIndicator = document.getElementById('Loading') || {style: {}};
    if ( location.hash.match(/^#/) ) {
      _Category = location.hash.replace(/^#/, '');
    }

    var src = 'crypto-js/build/rollups/' + _EncryptionMethod.toLowerCase() + '.js';
    FreeVault.Utils.LoadScript(src, function(error) {
      _$LoadingIndicator.style.display = 'none';

      if ( error ) {
        DisplayError("Failed to load crypto-library: " + src);
      }
      Initialize(!!error);
    });
  };

})();
