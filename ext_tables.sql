CREATE TABLE tx_nst3al_domain_model_logs
(
    status tinyint(4) DEFAULT '0' NOT NULL,
    extension_name  varchar(255) default '',
    source_file  varchar(255) default '',
    output_file  varchar(255) default '',
    target_language varchar(255) default '',
    request_mode  varchar(255) default '',
    content text
);