# slack-listener

- Create a Slack application at https://api.slack.com/apps.
- Got to: Add features and functionality -> Permissions -> Bot Token Scopes and add channels:history.
- Install to Workspace.
- Create a database (MySQL/MariaDB) table:
    ```sql
    create table messages
    (
        id         int auto_increment,
        channel    varchar(255) not null,
        message_ts double       not null,
        message    mediumtext   not null,
        constraint messages_id_uindex unique (id)
    );
    create index messages_ts__index on messages (message_ts);
    ```
- Set environment variables, see .env.sh.dist
- Setup a cron job, example:
    ```
    35 */8 * * * user . /path/to/.env.sh && /path/to/bin/console run >> /path/to/results-`date +\%Y-\%m`.log
    ```
