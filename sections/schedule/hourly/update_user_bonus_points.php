<?php

//------------------------ Update Bonus Points -------------------------//
// calcuation:
// Size * (0.0754 + (0.1207 * ln(1 + seedtime)/ (seeders ^ 0.55)))
// Size (convert from bytes to GB) is in torrents
// Seedtime (convert from hours to days) is in xbt_snatched
// Seeders is in torrents

$DB->query("
UPDATE users_main AS um
LEFT JOIN (
    SELECT
        xfu.uid AS ID,
        SUM(IFNULL((t.Size / (1024 * 1024 * 1024)) * (
            0.0433 + (
                (0.07 * LN(1 + (xfh.seedtime / (24)))) / (POW(GREATEST(tls.Seeders, 1), 0.35))
            )
        ), 0)) AS NewPoints
    FROM (
        SELECT DISTINCT uid,fid FROM xbt_files_users WHERE active='1' AND remaining=0 AND mtime > unix_timestamp(NOW() - INTERVAL 1 HOUR)
    ) AS xfu
    INNER JOIN xbt_files_history AS xfh ON (xfh.uid = xfu.uid AND xfh.fid = xfu.fid)
    INNER JOIN users_main AS um ON (um.ID = xfu.uid)
    INNER JOIN users_info AS ui ON (ui.UserID = xfu.uid)
    INNER JOIN torrents AS t ON (t.ID = xfu.fid)
    INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID)
    WHERE
        um.Enabled = '1' 
        AND ui.DisablePoints = '0'
    GROUP BY
        xfu.uid
) AS p ON um.ID = p.ID
SET um.BonusPoints=um.BonusPoints + CASE WHEN p.NewPoints IS NULL THEN 0 ELSE ROUND(p.NewPoints, 5) END");

$DB->query("SELECT UserID FROM users_info WHERE DisablePoints = '0'");
if ($DB->has_results()) {
    while(list($UserID) = $DB->next_record()) {
        $Cache->delete_value('user_stats_'.$UserID);
    }
}
