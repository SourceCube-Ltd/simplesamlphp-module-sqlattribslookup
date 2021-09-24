sqlattribslookup:LookupAttributesFromSQL
========================================

This SimpleSAMLphp auth proc filter allows you to provides additional attributes from a SQL datastore via a custom lookup. It is useful in situations where your
primary authsource is a directory (e.g. AD) that you do not have direct control over, and you need to add additional attributes for specific
users but cannot add them into the directory/modify the schema.

Installation
------------

Once you have installed SimpleSAMLphp, installing this module is
very simple.  Just execute the following command in the root of your
SimpleSAMLphp installation:

```
composer.phar require sourcecubeltd/simplesamlphp-module-sqlattribslookup:dev-main
```

where `dev-main` instructs Composer to install the `main` (**development**)
branch from the Git repository.

You then need to create the following table in your SQL database:

```sql
CREATE TABLE IF NOT EXISTS `samlLookup` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lookupattr` VARCHAR(100) NOT NULL,
    `sp` VARCHAR(250) DEFAULT '%',
    `value` TEXT,
    `expires` DATE DEFAULT '9999-12-31',
     PRIMARY KEY (`id`)
) DEFAULT CHARSET=utf8;
```

Usage
-----

This module provides the _sqlattribslookup:LookupAttributesFromSQL_ auth proc filter,
which can be used as follows:

```php
50 => [
    'class'      => 'sqlattribslookup:LookupAttributesFromSQL',
    'lookupAttr' => 'organization',
    'updateAttr' => 'eduPersonEntitlement',
    'replace'    => true,
    'database'   => [
        'dsn'       => 'mysql:host=localhost;dbname=simplesamlphp',
        'username'  => 'yourDbUsername',
        'password'  => 'yourDbPassword',
        'table'     => 'samlLookup',
    ],
],
```

Where the parameters are as follows:

* `class` - the name of the class, must be _sqlattribslookup:LookupAttributesFromSQL_

* `lookupAttr` - the attribute to use as the lookup for database searches, defaults to `urn:oid:2.5.4.10` (organization) if not specified.

* `updateAttr` - the attribute that will be updated based on the values in the database, defaults to `urn:oid:1.3.6.1.4.1.5923.1.1.1.7` (eduPersonEntitlement) if not specified.

* `replace` - behaviour when an existing attribute of the same name is encountered. If `false` (the default) then new values are pushed into an array, creating a multi-valued attribute. If `true`, then existing attributes of the same name are replaced (deleted).

* `ignoreExpiry` - ignore any expiry date (default is to ignore attributes that are beyond the date in the `expires` column).

* `database` - an array containing information about the data store, with the following parameters:

  * `dsn` - the data source name, defaults to _mysql:host=localhost;dbname=simplesamlphp_

  * `username` - the username to connect to the database, defaults to none (blank username)

  * `password` - the password to connect to the database, defaults to none (blank password)

  * `table` - the name of the table/view to search for attributes, defaults to `samlLookup`
