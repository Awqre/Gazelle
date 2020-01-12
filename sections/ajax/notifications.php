<?php
if (!check_perms('site_torrents_notify')) {
    json_die("failure");
}

define('NOTIFICATIONS_PER_PAGE', 50);
list($Page, $Limit) = Format::page_limit(NOTIFICATIONS_PER_PAGE);

$Results = $DB->query("
        SELECT
            SQL_CALC_FOUND_ROWS
            unt.TorrentID,
            unt.UnRead,
            unt.FilterID,
            unf.Label,
            t.GroupID
        FROM users_notify_torrents AS unt
        INNER JOIN torrents AS t ON (t.ID = unt.TorrentID)
        LEFT JOIN users_notify_filters AS unf ON (unf.ID = unt.FilterID)
        WHERE unt.UserID = $LoggedUser[ID]".
        ((!empty($_GET['filterid']) && is_number($_GET['filterid']))
            ? " AND unf.ID = '$_GET[filterid]'"
            : '')."
        ORDER BY TorrentID DESC
        LIMIT $Limit");
$GroupIDs = array_unique($DB->collect('GroupID'));

$DB->query('SELECT FOUND_ROWS()');
list($TorrentCount) = $DB->next_record();

if (count($GroupIDs)) {
    $TorrentGroups = Torrents::get_groups($GroupIDs);
    $DB->prepared_query('
        UPDATE users_notify_torrents
        SET UnRead = ?
        WHERE UserID = ?
        ', '0', $LoggedUser['ID']
    );
    $Cache->delete_value("notifications_new_$LoggedUser[ID]");
}

$DB->set_query_id($Results);

$JsonNotifications = [];
$NumNew = 0;

$FilterGroups = [];
while ($Result = $DB->next_record(MYSQLI_ASSOC)) {
    if (!$Result['FilterID']) {
        $Result['FilterID'] = 0;
    }
    if (!isset($FilterGroups[$Result['FilterID']])) {
        $FilterGroups[$Result['FilterID']] = [];
        $FilterGroups[$Result['FilterID']]['FilterLabel'] = ($Result['Label'] ? $Result['Label'] : false);
    }
    array_push($FilterGroups[$Result['FilterID']], $Result);
}
unset($Result);

foreach ($FilterGroups as $FilterID => $FilterResults) {
    unset($FilterResults['FilterLabel']);
    foreach ($FilterResults as $Result) {
        $TorrentID = $Result['TorrentID'];
        $Group = $TorrentGroups[$Result['GroupID']];

        $Torrents = isset($Group['Torrents']) ? $Group['Torrents'] : [];
        $TorrentInfo = $Group['Torrents'][$TorrentID];

        if ($Result['UnRead'] == 1) {
            $NumNew++;
        }

        $JsonNotifications[] = [
            'torrentId' => (int)$TorrentID,
            'groupId' => (int)$Group['ID'],
            'groupName' => $Group['Name'],
            'groupCategoryId' => (int)$Group['CategoryID'],
            'wikiImage' => $Group['WikiImage'],
            'torrentTags' => $Group['TagList'],
            'size' => (float)$TorrentInfo['Size'],
            'fileCount' => (int)$TorrentInfo['FileCount'],
            'format' => $TorrentInfo['Format'],
            'encoding' => $TorrentInfo['Encoding'],
            'media' => $TorrentInfo['Media'],
            'scene' => $TorrentInfo['Scene'] == 1,
            'groupYear' => (int)$Group['Year'],
            'remasterYear' => (int)$TorrentInfo['RemasterYear'],
            'remasterTitle' => $TorrentInfo['RemasterTitle'],
            'snatched' => (int)$TorrentInfo['Snatched'],
            'seeders' => (int)$TorrentInfo['Seeders'],
            'leechers' => (int)$TorrentInfo['Leechers'],
            'notificationTime' => $TorrentInfo['Time'],
            'hasLog' => $TorrentInfo['HasLog'] == 1,
            'hasCue' => $TorrentInfo['HasCue'] == 1,
            'logScore' => (float)$TorrentInfo['LogScore'],
            'freeTorrent' => $TorrentInfo['FreeTorrent'] == 1,
            'logInDb' => $TorrentInfo['HasLog'] == 1,
            'unread' => $Result['UnRead'] == 1
        ];
    }
}

json_print("success", [
    'currentPages' => intval($Page),
    'pages' => ceil($TorrentCount / NOTIFICATIONS_PER_PAGE),
    'numNew' => $NumNew,
    'results' => $JsonNotifications
]);
