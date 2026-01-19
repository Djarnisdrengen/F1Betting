### Adding support for betting pool size

## New column for bet size
ALTER TABLE settings ADD bet_size INT

## New column for betting pool won
ALTER TABLE races ADD bettingpool_won tinyint(1), bettingpool_size INT

## Updated
admin.php
races.php
style.css