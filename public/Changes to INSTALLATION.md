### Adding support for betting pool size

## New column for bet size
ALTER TABLE settings ADD bet_size INT

## New column for betting pool won
ALTER TABLE races ADD bettingpool_won tinyint(1), bettingpool_size INT

## Settings
ALTER TABLE settings ADD COLUMN bet_size INT DEFAULT 10;

## Users
ALTER TABLE users ADD COLUMN in_competition TINYINT(1) DEFAULT 0;

## config.php
New version make backup
copy secrets etc. from backup to new config.php

## Updated
admin.php
races.php
style.css