<?php
/*
 * $_REQUEST['action'] is artist, collages, requests or torrents (default torrents)
 * $_REQUEST['type'] depends on the page:
 *     collages:
 *        created = comments left on one's collages
 *        contributed = comments left on collages one contributed to
 *     requests:
 *        created = comments left on one's requests
 *        voted = comments left on requests one voted on
 *     torrents:
 *        uploaded = comments left on one's uploads
 *     If missing or invalid, this defaults to the comments one made
 */

function linkBuild(string $id, string $action): string {
    preg_match('/^(.*?)s?$/', $action, $match);
    return sprintf('<a href="comments.php?action=%s%s" class="brackets">%s comments</a>',
        $action, $id, ucfirst($match[1])
    );
}

function createdBy(int $ownProfile, string $user, string $objects): string {
    return $ownProfile ? "your $objects" : "$objects created by $user";
}

// who is it?
if (!isset($_GET['id'])) {
    $UserID   = $LoggedUser['ID'];
    $Username = $LoggedUser['Username'];
} else {
    $UserID = (int)$_GET['id'];
    if (!$UserID) {
        error(404);
    }
    $UserInfo = Users::user_info($UserID);
    $Perms = Permissions::get_permissions($UserInfo['PermissionID']);
    if (!check_paranoia('torrentcomments', $UserInfo['Paranoia'], $Perms['Class'], $UserID)) {
        error(403);
    }
    $Username = $UserInfo['Username'];
}

if ($LoggedUser['ID'] == $UserID) {
    $ownProfile = true;
    $linkId     = '';
    $have       = 'you have';
    $who        = 'you';
    $whoHas     = 'you have';
} else {
    $ownProfile = false;
    $linkId     = "&amp;id=$UserID";
    $have       = "$Username has";
    $who        = $Username;
    $whoHas     = Users::format_username($UserID, false, false, false) . ' has';
}

$Action   = $_REQUEST['action'] ?? 'torrents';
$Type     = $_REQUEST['type'] ?? 'default';
$BaseLink = "comments.php?action=$Action$linkId";

// SQL components
$condArgs   = [];
$condition = [];
$Join     = [];
$joinArgs = [];

$ActionLinks = [];
$TypeLinks = [];

switch ($Action) {
    case 'artist':
        $Header = 'Artist comments left by %s';
        $Title = 'Artist comments left by ' . $who;

        $table       = 'artists_group AS ag';
        $idField     = 'ag.ArtistID';
        $nameField   = 'ag.Name';
        $condition[] = "C.AuthorID = ?";
        $condArgs[]  = $UserID;

        $ActionLinks = [
            linkBuild($linkId, 'collages'),
            linkBuild($linkId, 'requests'),
            linkBuild($linkId, 'torrents'),
        ];
        break;

    case 'collages':
        switch ($Type) {
            case 'created':
                $Header = 'Comments left on collages %s created';
                $Title  = 'Comments left on collages ' . $who . ' created';
                $condition[] = "cl.UserID = ?";
                $condition[] = "C.AuthorID != ?";
                $condArgs[] = $UserID;
                $condArgs[] = $UserID;
                $TypeLinks = [
                    [$BaseLink, "Display comments left on collages $have made"],
                    [$BaseLink . "&amp;type=contributed", "Display comments left on collages $have contributed to"],
                ];
                break;
            case 'contributed':
                $Header = 'Comments left on collages %s contributed to';
                $Title  = 'Comments left on collages ' . $have . ' contributed to';
                $condition[] = "C.AuthorID != ? AND cl.ID IN (
                    SELECT DISTINCT CollageID FROM collages_torrents ct WHERE ct.UserID = ?
                    UNION ALL
                    SELECT DISTINCT CollageID FROM collages_artists ca WHERE ca.UserID = ?)";
                $condArgs = array_merge($condArgs, [$UserID, $UserID, $UserID]);
                $TypeLinks = [
                    [$BaseLink, "Display comments left on collages $have made"],
                    ["$BaseLink&amp;type=created", "Display comments left on " . createdBy($ownProfile, $Username, 'collages')],
                ];
                break;
            default:
                $Header = 'Collage comments left by %s';
                $Title  = 'Collage comments left by ' . $who;
                $condition[] = "C.AuthorID = ?";
                $condArgs[]  = $UserID;
                $TypeLinks = [
                    ["$BaseLink&amp;type=contributed", "Display comments left on collages $have contributed to"],
                    ["$BaseLink&amp;type=created", "Display comments left on " . createdBy($ownProfile, $Username, 'collages')],
                ];
                break;
        }

        $table       = 'collages AS cl';
        $idField     = 'cl.ID';
        $nameField   = 'cl.Name';
        $condition[] = "cl.Deleted = '0'";

        $ActionLinks = [
            linkBuild($linkId, 'artist'),
            linkBuild($linkId, 'requests'),
            linkBuild($linkId, 'torrents'),
        ];
        break;

    case 'requests':
        switch($Type) {
            case 'created':
                $Header = 'Comments left on requests %s created';
                $Title  = 'Comments left on requests ' . $who . ' created';
                $condition[] = "r.UserID = ?";
                $condition[] = "C.AuthorID != ?";
                $condArgs[] = $UserID;
                $condArgs[] = $UserID;
                $TypeLinks = [
                    [$BaseLink, "Display comments left on requests $have made"],
                    ["$BaseLink&amp;type=voted", "Display comments left on requests $have voted on"],
                ];
                break;
            case 'voted':
                $Header = 'Comments left on requests %s voted on';
                $Title  = 'Comments left on requests ' . $who . ' voted on';
                $Join[] = 'INNER JOIN requests_votes rv ON (rv.RequestID = r.ID)';
                $condition[] = "rv.UserID = ?";
                $condition[] = "C.AuthorID != ?";
                $condArgs[] = $UserID;
                $condArgs[] = $UserID;
                $TypeLinks = [
                    [$BaseLink, "Display comments left on requests $have made"],
                    ["$BaseLink&amp;type=created", "Display comments left on requests $have created"],
                ];
                break;
            default:
                $Header = 'Request comments left by %s';
                $Title  = 'Request comments left by ' . $who;
                $condition[] = "C.AuthorID = ?";
                $condArgs[] = $UserID;
                $TypeLinks = [
                    ["$BaseLink&amp;type=created", "Display comments left on requests $have created"],
                    ["$BaseLink&amp;type=voted", "Display comments left on requests $have voted on"],
                ];
                break;
        }

        $table      = 'requests AS r';
        $idField    = 'r.ID';
        $nameField  = 'r.Title';

        $ActionLinks = [
            linkBuild($linkId, 'artist'),
            linkBuild($linkId, 'collages'),
            linkBuild($linkId, 'torrents'),
        ];
        break;

    case 'torrents':
        switch($Type) {
            case 'uploaded':
                $Header = 'Comments left on torrents %s uploaded';
                $Title  = 'Comments left on torrents ' . $who . ' uploaded';
                $Join[] = 'INNER JOIN torrents t ON (t.GroupID = tg.ID)';
                $condition[] = 'C.AddedTime > t.Time';
                $condition[] = "C.AuthorID != ?";
                $condition[] = "t.UserID = ?";
                $condArgs[] = $UserID;
                $condArgs[] = $UserID;
                $TypeLinks[] = [$BaseLink, "Display comments $have made on torrents"];
                break;
            default:
                $Header = 'Torrent comments left by %s';
                $Title  = 'Torrent comments left by ' . $who;
                $condition[] = "C.AuthorID = ?";
                $condArgs[] = $UserID;
                $TypeLinks[] = ["$BaseLink&amp;type=uploaded", "Display comments left on torrents $have uploaded"];
                break;
        }

        $table     = 'torrents_group AS tg';
        $idField   = 'tg.ID';
        $nameField = 'tg.Name';

        $ActionLinks = [
            linkBuild($linkId, 'artist'),
            linkBuild($linkId, 'collages'),
            linkBuild($linkId, 'requests'),
        ];
        break;
}

$Join[] = "INNER JOIN comments C ON (C.Page = ? AND C.PageID = $idField)";
$joinArgs[] = $Action;
$Join = implode("\n", $Join);
$cond = $condition ? 'WHERE ' . implode(" AND ", $condition) : '';

$Count = $DB->scalar("
    SELECT count(DISTINCT(C.ID))
    FROM $table
    $Join
    $cond
    ", ...array_merge($joinArgs, $condArgs)
);

// Posts per page limit stuff
$PerPage = $LoggedUser['PostsPerPage'] ?? POSTS_PER_PAGE;
[$Page, $Limit] = Format::page_limit($PerPage);
$Pages = Format::get_pages($Page, $Count, $PerPage, 11);

$Comments = $DB->prepared_query("
    SELECT
        C.AuthorID,
        C.Page,
        C.PageID,
        $nameField,
        C.ID,
        C.Body,
        C.AddedTime,
        C.EditedTime,
        C.EditedUserID
    FROM $table
    $Join
    $cond
    GROUP BY C.ID
    ORDER BY C.ID DESC
    LIMIT $Limit
    ", ...array_merge($joinArgs, $condArgs)
);

if ($Action == 'requests') {
    $RequestIDs = array_flip(array_flip($DB->collect('PageID')));
    $Artists = [];
    foreach ($RequestIDs as $RequestID) {
        $Artists[$RequestID] = Requests::get_artists($RequestID);
    }
    $DB->set_query_id($Comments);
} elseif ($Action == 'torrents') {
    $GroupIDs = array_flip(array_flip($DB->collect('PageID')));
    $Artists = Artists::get_artists($GroupIDs);
    $DB->set_query_id($Comments);
}

$Links = implode(' ', $ActionLinks)
    . ($TypeLinks
        ? (' <br />' . implode(' ', array_map(
            function ($x) {
                return sprintf('<a href="%s" class="brackets">%s</a>', $x[0], $x[1]);
            }, $TypeLinks
        )))
        : ''
    );

View::show_header(sprintf($Title, 'bbcode,comments'));
?>
<div class="thin">
    <div class="header">
        <h2><?= sprintf($Header, $ownProfile
            ? 'you'
            : Users::format_username($UserID, false, false, false)
        ) ?></h2>
<?php if ($Links) { ?>
        <div class="linkbox">
            <?= $Links ?>
        </div>
<?php } ?>
    </div>
    <div class="linkbox">
        <?= $Pages ?>
    </div>
<?php if (!$Count) { ?>
    <div class="center">No results.</div>
<?php
} else {
    $DB->set_query_id($Comments);
    $isAdmin = check_perms('site_admin_forums');
    $isMod = check_perms('site_moderate_forums');
    while ([$AuthorID, $Page, $PageID, $Name, $PostID, $Body, $AddedTime, $EditedTime, $EditedUserID] = $DB->next_record()) {
        switch ($Page) {
            case 'artist':
                $Header = " on <a href=\"artist.php?id=$PageID\">$Name</a>";
                break;
            case 'collages':
                $Header = " on <a href=\"collages.php?id=$PageID\">$Name</a>";
                break;
            case 'requests':
                $Header = ' on ' . Artists::display_artists($Artists[$PageID]) . " <a href=\"requests.php?action=view&id=$PageID\">$Name</a>";
                break;
            case 'torrents':
                $Header = ' on ' . Artists::display_artists($Artists[$PageID]) . " <a href=\"torrents.php?id=$PageID\">$Name</a>";
                break;
        }
        $author = Users::user_info($AuthorID);
        $ownProfile = $AuthorID == $LoggedUser['ID'];
        echo G::$Twig->render('comment/comment.twig', [
            'avatar'      => Users::show_avatar($author['Avatar'], $AuthorID, $author['Username'], $LoggedUser['DisableAvatars']),
            'body'        => Text::full_format($Body),
            'edited'      => $EditedUserID,
            'editor'      => Users::format_username($EditedUserID, false, false, false),
            'edit_time'   => time_diff($EditedTime, 2, true, true),
            'id'          => $PostID,
            'is_admin'    => $isAdmin,
            'header'      => '<strong>' . Users::format_username($AuthorID, true, true, true, true, false) . '</strong> ' . time_diff($AddedTime) . $Header,
            'show_avatar' => Users::has_avatars_enabled(),
            'show_delete' => $isMod,
            'show_edit'   => $isMod || $ownProfile,
            'show_warn'   => check_perms('users_warn') && !$ownProfile && $LoggedUser['Class'] >= $author['Class'],
            'show_unread' => false,
            'url'         => Comments::get_url($Page, $PageID, $PostID),
            'username'    => $author['Username'],
        ]);
    }
}
?>
    <div class="linkbox">
        <?= $Pages ?>
    </div>
</div>
<?php
View::show_footer();
