# slack-listener

Needs PHP 8.1+.

- Create a Slack application at https://api.slack.com/apps.
- Got to: Add features and functionality -> Permissions -> Bot Token Scopes and add channels:history (or groups:history for a private channel), and users:read.
- Install to Workspace.
- Create a database (MySQL/MariaDB) table:
    ```sql
    create table messages
    (
        id         int auto_increment,
        channel    varchar(255) not null,
        message_ts double       not null,
        message    mediumtext   not null,
        relayed    tinyint      default 0   not null,
        constraint messages_id_uindex unique (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    create index messages_ts__index on messages (message_ts);
    ```
- Set environment variables, see .env.sh.dist
- Setup a cron job, example:
    ```
    35 */8 * * * user . /path/to/.env.sh && /path/to/bin/console run >> /path/to/results-`date +\%Y-\%m`.log
    ```
## Updating the Database Schema

- If you're updating from a version without relay functionality, modify the messages table as follows:
    ```sql
    alter table messages add relayed tinyint default 0 not null;
    ```
