/*!
 * FreeVault (c) 2014 Copyleft Software AS
 */
"use strict";

(function() {
  window.FreeVault = window.FreeVault || {};
  FreeVault.Utils  = FreeVault.Utils  || {};

  /**
   * Load JavaScript
   */
  function LoadScript(src, callback) {
    var loaded  = false;
    var res     = document.createElement("script");
    res.type    = "text/javascript";
    res.charset = "utf-8";
    res.onreadystatechange = function() {
      if ( (this.readyState == 'complete' || this.readyState == 'loaded') && !loaded) {
        loaded = true;
        callback(false);
      }
    };
    res.onload = function() {
      if ( loaded ) { return; }
      loaded = true;
      callback(false);
    };
    res.onerror = function() {
      if ( loaded ) { return; }
      loaded = true;
      callback(true);
    };
    res.src = src;

    document.getElementsByTagName("head")[0].appendChild(res);
  }

  /**
   * Ajax POST method
   */
  function AjaxPost(url, post, onSuccess, onError, opts) {
    if ( !url ) { throw "AjaxPost: No URL given"; }

    onSuccess = onSuccess || function() {};
    onError   = onError   || function() {};

    var httpRequest;
    if (window.XMLHttpRequest) {
      httpRequest = new XMLHttpRequest();
    } else if (window.ActiveXObject) { // IE
      try {
        httpRequest = new ActiveXObject("Msxml2.XMLHTTP");
      } catch (e) {
        try {
          httpRequest = new ActiveXObject("Microsoft.XMLHTTP");
        } catch (e) {}
      }
    }

    if ( !httpRequest ) {
      throw('AjaxPost: Cannot create an XMLHTTP instance');
    }

    httpRequest.onreadystatechange = function() {
      if (httpRequest.readyState === 4) {
        var response = httpRequest.responseText;
        var error = "";
        var ctype = this.getResponseHeader('content-type');

        if ( ctype === 'application/json' ) {
          try {
            response = JSON.parse(httpRequest.responseText);
          } catch ( e ) {
            response = null;
            error = "An error occured while parsing JSON: " + e;
          }
        }

        if ( httpRequest.status === 200 ) {
          onSuccess(response, httpRequest, url);
        } else {
          if ( !error && (ctype !== 'application/json') ) {
            error = "Backend error: " + (httpRequest.responseText || "Fatal Error");
          }
          onError(error, response, httpRequest, url);
        }
      }
    };

    if ( typeof post !== 'String' ) {
      post = (JSON.stringify(post));
    }
    httpRequest.open('POST', url);
    //httpRequest.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    httpRequest.setRequestHeader('Content-Type', 'application/json');
    httpRequest.send(post);

    return true;
  }

  /**
   * Select Text by range in Input
   */
  function SelectRange(field, start, end) {
    if ( !field ) { throw "Cannot select range: missing element"; }
    if ( typeof start === 'undefined' || typeof end === 'undefined' ) { throw "Cannot select range: mising start/end"; }

    if ( field.createTextRange ) {
      var selRange = field.createTextRange();
      selRange.collapse(true);
      selRange.moveStart('character', start);
      selRange.moveEnd('character', end);
      selRange.select();
      field.focus();
    } else if ( field.setSelectionRange ) {
      field.focus();
      field.setSelectionRange(start, end);
    } else if ( typeof field.selectionStart != 'undefined' ) {
      field.selectionStart = start;
      field.selectionEnd = end;
      field.focus();
    }
  }

  //
  // EXPORTS
  //
  FreeVault.Utils.LoadScript  = LoadScript;
  FreeVault.Utils.AjaxPost    = AjaxPost;
  FreeVault.Utils.SelectRange = SelectRange;

})();
