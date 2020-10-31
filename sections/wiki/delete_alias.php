<?php
authorize();

$alias = trim($_GET['alias']);
$wikiMan = new Gazelle\Manager\Wiki;
$articleId = $wikiMan->alias($alias);
if (!$wikiMan->editAllowed($articleId, $LoggedUser['EffectiveClass'])) {
    error(403);
}
$wikiMan->removeAlias($alias);
