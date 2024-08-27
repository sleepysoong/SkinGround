-- #! mysql

-- #{ init
CREATE TABLE IF NOT EXISTS skin_url (
    xuid BIGINT UNSIGNED PRIMARY KEY,
    url TEXT
);
-- #}

-- #{ set
-- #    :xuid int
-- #    :url string
INSERT INTO skin_url (xuid, url)
VALUES (:xuid, :url) ON DUPLICATE KEY UPDATE url = :url;
-- #}

-- #{ get
-- #    :xuid int
SELECT url FROM skin_url
WHERE xuid = :xuid;
-- #}