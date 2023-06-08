# theteknocat/drupal-updater

A command-line tool for automating Drupal 8+ site updates with the following features:

* Fully automated updates for multiple sites
* Option to automatically commit and push to a git repository
* Automatic download and backup of production database
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

## Commands

This is still a work in progress, but this is what the expected commands will be:

### Update Sites

Runs the drupal updates on all the sites in the drupalup.sites.yml file.

Command: `drupalup update`

Options:

* `--uri=example.com` - Optional. Specify a single site to update. Must match a URI in the drupalup.sites.yml file.
* `--notify=true` - Optional. Whether or not to send an email notification. Defaults to true.
* `--dry-run` - perform a dry-run, meaning run all the updates, send a notification (if enabled), but do not perform any git actions.

### Rollback Update

Rolls back an update. This will reset the site back to the main branch, restore the database from the backup made and then run composer install to restore the composer libraries to their previous state.

The purpose of this command is so you can test deployment of the updates prior to production deployment. It does not perform any git operations or send any email notifications. It only works if the site was updated already by the update command.

Command: `drupalup rollback`

Options:

* `--uri=example.com` - Required. Must match a URI in the drupalup.sites.yml file.

The rollback process will fail if the source is not currently on the updates branch or there is no database backup file found.
