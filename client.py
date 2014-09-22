#!/usr/bin/env python
#
# FreeVault (c) Copyleft Software AS
#

import urllib2
import json
import base64
import argparse
import os
import ConfigParser
import textwrap

#
# Default configuration
#
DEFAULT_CONFIG = os.path.expanduser("~") + '/.fvcli'
DEFAULT_HOST = 'http://freevault.local'
DEFAULT_USERNAME = 'username'
DEFAULT_PASSWORD = 'password'
VERBOSE = False

#
# API Methods
#
class VaultAPI:

  def __init__(self, host, username, password):
    self.host = host
    self.username = username
    self.password = password

  # Wrapper for calling API
  def _call(self, action, args = None):
    data = {'action': action}
    if args is not None:
      for k in args:
        data[k] = args[k]

    if VERBOSE:
      print "Using: %s" % self.host
      print data

    req = urllib2.Request(self.host)
    req.add_header('User-Agent', 'freevault-python')
    req.add_header('Content-Type', 'application/json')

    base64string = base64.encodestring('%s:%s' % (self.username, self.password)).replace('\n', '')
    req.add_header("Authorization", "Basic %s" % base64string)

    f = urllib2.urlopen(req, json.dumps(data))
    r = f.read()

    try:
      return json.loads(r)
    except ValueError, e:
      raise Exception("Exception: %s\nResponse: %s" % (e, r))

    return None

  # List an entry
  def get(self, eid):
    response = self._call('get', {'id': eid})
    for k, v in response['result'].iteritems():
      print "%s: %s" % (k, v)

  # List all categories
  def categories(self):
    response = self._call('categories')

    print "Your categories"
    if response['result']:
      for cat in response['result']:
        print "- %s (%d)" % (cat, response['result'][cat])

  # List all entries in given category
  def list(self, cid):
    response = self._call('list', {'category': cid})
    print "Entries in category: %s" % cid
    for i in response['result']:
      print """
Id: %s
Title: %s
Description: %s
Date: %s (last edit: %s)
""" % (i['id'], i['title'], i['description'], i['created_on'], i['edited_on'])

#
# Creates a config file
#
def create_config(config, cfg, username=None, password=None, host=None):
  if config is None:
    config = ConfigParser.RawConfigParser()
  if username is None:
    username = DEFAULT_USERNAME
  if password is None:
    password = DEFAULT_PASSWORD
  if host is None:
    host = DEFAULT_HOST

  config.add_section('FreeVault')
  config.set('FreeVault', 'username', username)
  config.set('FreeVault', 'password', password)
  config.set('FreeVault', 'host', host)

  with open(cfg, 'wb+') as configfile:
    config.write(configfile)

#
# Main program
#
if __name__ == '__main__':
  help_desc = """Access FreeVault via CLI.

------------------------------------------------------
Actions:

save              Save given configuration
categories        List categories
list <category>   List entries in given category
get <id>          Show entry with given id

------------------------------------------------------

"""

  parser = argparse.ArgumentParser(
      formatter_class=argparse.RawDescriptionHelpFormatter,
      description=textwrap.dedent(help_desc) )

  parser.add_argument('action', metavar='ACTION', type=str,
      help='action')

  parser.add_argument('arguments', metavar='ARGS', default=[], nargs='*',
      help='argument(s)')

  parser.add_argument('-c', '--config', type=str, default=DEFAULT_CONFIG,
      help='Configuration file (default: %s)' % DEFAULT_CONFIG)

  parser.add_argument('--host', type=str, default=None, required=False,
      help='Connection hostname')

  parser.add_argument('--username', type=str, default=None, required=False,
      help='Connection username')

  parser.add_argument('--password', type=str, default=None, required=False,
      help='Connection password')

  parser.add_argument('--verbose', action='store_true', required=False,
      help='Show debugging info etc.')

  args = parser.parse_args()

  # Read config file and arguments
  cfg = os.path.realpath(args.config)
  username = None
  password = None
  host = None
  config = ConfigParser.RawConfigParser()

  VERBOSE = args.verbose

  try:
    config.read(cfg)
  except Exception, e:
    create_config(config, cfg)

  if 'username' in args and (args.username is not None):
    username = args.username
  else:
    try:
      username = config.get('FreeVault', 'username')
    except Exception, e:
      pass

  if 'password' in args and (args.password is not None):
    password = args.password
  else:
    try:
      password = config.get('FreeVault', 'password')
    except Exception, e:
      pass

  if 'host' in args and (args.host is not None):
    host = args.host
  else:
    try:
      host = config.get('FreeVault', 'host')
    except Exception, e:
      pass

  # Create config file
  if args.action == 'save':
    print "Saving configuration to: %s" % cfg
    create_config(None, cfg, username, password, host)

  # API call
  else:
    if (username is None) or (password is None) or (host is None):
      print "You have no connection parameters set up. Check your configuration"

    v = VaultAPI(host, username, password)
    try:
      getattr(v, args.action)(*args.arguments)
    except TypeError, e:
      print "Invalid arguments: %s" % e
    except AttributeError, e:
      print "Invalid action: %s" % e

