# PSB User Deployment

## Optimized import from (or export to) JSON of TYPO3 users and user groups

---
The file format used here offers better readability and maintainability than
simple database dumps. You can split user data into multiple files (e.g. one for
backend and one for fronent) and define default values as well es variables for
often used configurations to keep the files small and easy to read.

---

- [What does it do?](#what-does-it-do)
- [Why should you use it?](#why-should-you-use-it)
- [How it works](#how-it-works)
    - [Export](#export)
    - [Default values](#default-values)
    - [Variables](#variables)
    - [File imports](#file-imports)
    - [Deployment](#deployment)

### What does it do?

This package is an extension for TYPO3 CMS that provides a way to manage user
configuration on a file basis instead of using the database as the primary
system. You can edit the JSON files directly or use the TYPO3 backend to edit
the users and groups as usual. The data can be exported to JSON via the command
line.

Tables supported:

- be_groups
- be_users
- fe_groups
- fe_users
- sys_filemounts

**IMPORTANT!**
<br>
No sensitive data (passwords, mfa configuration, etc.) will be exported.

### Why should you use it?

This package was created to

- deploy user configuration to different environments (e.g. staging, production)
  in a consistent and reliable way
- apply the benefits of version control to non-sensitive user data

### How it works

#### Export

It's a good idea to first export the current user configuration.

```bash
./vendor/bin/typo3 psbUserDeployment:deploy ./path/to/your/configuration.json
```

| Argument   | Description                                                           | Example                                                 |
|------------|-----------------------------------------------------------------------|---------------------------------------------------------|
| `filename` | target path and filename relative to web root or starting with `EXT:` | `EXT:my_extension/Configuration/psbUserDeployment.json` |

Shortened example output (comments are not part of the JSON, of course, but are
added here for helpful explanations):

```json
{
    "be_groups": { // table name
        "_default": {
            "pid": 0
        },
        "_variables": {
        },
        "Advanced editor": { // The key is used as title and must be unique obviously. Providing a title inside this record would have no effect.
            "non_exclude_fields": "pages:backend_layout_next_level,pages:backend_layout,pages:description,pages:media,",
            "subgroup": [ // Groups are referenced by their title.
                "Basic editor"
            ]
        },
        "Basic editor": {
            "tables_modify": "pages,tt_content",
            "tables_select": "pages,tt_content"
        }
    },
    "be_users": { // table name
        "_default": {
            "lang": "default",
            "pid": 0
        },
        "_variables": {
        },
        "jadoe": { // The key is used as username and must be unique obviously. Providing a username inside this record would have no effect.
            "groups": [ // Groups are referenced by their title.
                "Basic editor",
                "Another group"
            ],
            "name": "Jane Doe"
        },
        "jodoe": {
            "groups": [
                "Advanced editor"
            ],
            "name": "John Doe"
        }
    }
}
```

As can be seen in the example, **no UIDs are used in the configuration!**
<br>
The commands will resolve the UIDs of the groups, users and filemounts
automatically. This is done by looking up the title and replacing it with the
UID (and vice versa). This way, you don't have to worry about keeping the UIDs
in sync and searching the JSON file for references is easier.

#### Default values

The ExortCommand defines sets of default values for each table. These defaults
are written to "_default" in the JSON file. Only the values that are different
from that need to be specified in the single records.

#### Variables

The "_variables" section is used to define variables that can be used in the
configuration. This is useful for values that are used multiple times in the
configuration, for example, if you have a set of groups or a description that is
used for multiple users. Variables are referenced by their name, e.g.
`@myVariable`. The value of a variable is used, when the value of a field matches
it **exactly**.

Example:

```json
{
    "be_groups": {
        "_variables": {
            "@myVariable": "This is a description."
        },
        "Content Editor": {
            "description": "@myVariable" // Variable will be replaced.
        }
        "SEO Editor": {
            "description": "@myVariable, some other description" // Variable will not be replaced!
        }
    }
}
```

You do not have to use the "@" sign, but it is recommended to keep to a
naming convention to avoid confusion with other values.

#### File imports

There is a special property named *"files"* on the first level that can be used to
split the configuration into multiple files. This might be useful when dealing
with a lot of records. The files will be imported in the given order and merged
into one configuration. If a field value of a record is defined in multiple
files, the last definition will be used.

Example:

```json
{
    "files": [
        "EXT:my_extension/Configuration/UserDeployment/backend.json",
        "EXT:my_extension/Configuration/UserDeployment/frontend.json"
    ],
}
```

#### Deployment

The configuration can be deployed to the database via the command line:

```bash
./vendor/bin/typo3 psbUserDeployment:deploy --dry-run --remove ./path/to/your/configuration.json
```

| Option      | Short | Description                                                              |
|-------------|-------|--------------------------------------------------------------------------|
| `--dry-run` | `-d`  | Only show what would be done, but do not execute the changes.            |
| `--remove`  | `-x`  | Remove all records (soft delete) that are not in the configuration file. |

**Steps:**

1. Check if "files" is defined. If so, load the additional files and merge them
   into one configuration.
2. Iterate over the given tables and records.
3. Get the default values for the table and merge them with the record values.
   If a field is not defined in the record, the default value will be used.
4. Look for variable references and replace them with the actual values.
5. Resolve group references and replace them with the actual UIDs.
6. Check if the record already exists in the database.
    1. If it does, update the record with the new values.
    2. If it does not, create a new record with the given values.
7. If the `--remove` option is set, remove all records that are not in the
   configuration file. This will only soft delete the records, so they can be
   restored later if needed.
