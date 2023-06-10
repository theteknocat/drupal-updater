# theteknocat/drupal-updater

A command-line tool for automating Drupal 8+ site updates with the following features:

* Fully automated updates for multiple sites
* Option to automatically commit and push to a git repository
* Automatic download of production database (if a drush alias is present)
* Backup of database prior to updates
* Function to rollback and re-run updates for testing
* Detailed summary of updates that can be used for the git commit
* Option to send email notifications (including the detailed summary)
* Options for handling scaffold files that may have been overriden in the project

This is a new project in progress, please stand by.

## Configuration Files

The updater will look for it's configuration files in the following directories, using the first one it finds in this order of preference:

* ~/drupalup
* ~
* /usr/local/etc/drupalup
* /usr/local/etc

The primary configuration file must be named "drupalup.settings.yml" and placed in one of the above locations. The secondary configuration file required is drupalup.sites.yml, which must also be in one of the above locations, ideally in the same place as the drupalup.settings.yml to make it easier to maintain.

The reason the list of sites is in a separate file from the base configuration is so you can easily maintain just the site list without worrying about accidental changes to the base config.

You can find sample configuration files in the sample-config folder.

## Pre-requisites

### Git

Git must be installed on the system as it will be used to check the current state of the codebase, as well as perform any git operations if not running updates in dry-run mode.

When running one of the commands, it needs to ensure that the codebase has no new or modified files and that it is currently on the appropriate branch. This is to prevent breaking something in case the site currently has some other work in progress.

When running updates and not using dry-run mode, it will also be used to commit the updates to the updates branch and push them to the remote repository.

### Composer

Composer must be installed on the system since that is what is used to install updates with. It will require the minimum version of composer specified at [https://www.drupal.org/docs/system-requirements/composer-requirements](https://www.drupal.org/docs/system-requirements/composer-requirements).

### Drush

The script will expect to find the drush binary in vendor/bin within the codebase of each site. If it is not found, the site will be skipped and an error notification generated. If you have specified drush under require-dev in the composer file, then the system running this script needs to have the sites all installed with the dev requirements.

## Commands

This is still a work in progress, but this is what the expected commands will be:

### Update Sites

Runs the drupal updates on all the sites in the drupalup.sites.yml file. This is the default command and does not have to be supplied as an argument when calling the script. This is the default command.

Command: `drupalup [update]`

Options:

* `<uri>` - Optional. Specify a single site to update. Must match a URI in the drupalup.sites.yml file.
* `--list` - Optional. Only applies if `<uri>` is not supplied. Lists all available sites allowing the user to select one.
* `--notify` - Optional. Send an email notification on completion.
* `--dry-run` - perform a dry-run, meaning run all the updates, send a notification (if enabled), but do not perform any git actions.

### Rollback Update

Rolls back an update. This will reset the site back to the main branch, restore the database from the backup made and then run composer install to restore the composer libraries to their previous state.

The purpose of this command is so you can test deployment of the updates prior to production deployment. It does not perform any git operations or send any email notifications. It only works if the site was updated already by the update command.

Command: `drupalup rollback`

Options:

* `<uri>` - Optional. Must match a URI in the drupalup.sites.yml file. If not supplied, all available sites will be listed for the user to choose the one to rollback.

The rollback process will fail if the source is not currently on either the updates or master branch or there is no database backup file found.
