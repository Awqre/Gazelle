<?php
function display_perm($Key, $Title) {
    global $Values;
    $Perm = "<input type=\"checkbox\" name=\"perm_$Key\" id=\"$Key\" value=\"1\"";
    if (!empty($Values[$Key])) {
        $Perm .= ' checked="checked"';
    }
    $Perm .= " /> <label for=\"$Key\">$Title</label><br />";
    echo "$Perm\n";
}

$DB->prepared_query("
    SELECT ID, Name
    FROM staff_groups
    ORDER BY Sort");
$Groups = $DB->to_array(false, MYSQLI_ASSOC);

View::show_header('Manage Permissions', 'validate');

echo $Val->GenerateJS('permissionsform');
?>
<form class="manage_form" name="permissions" id="permissionsform" method="post" action="" onsubmit="return formVal();">
    <input type="hidden" name="action" value="permissions" />
    <input type="hidden" name="auth" value="<?=$LoggedUser['AuthKey']?>" />
    <input type="hidden" name="id" value="<?=display_str($_REQUEST['id']); ?>" />
    <div class="linkbox">
        <a href="tools.php?action=permissions" class="brackets">Back to permission list</a>
        <a href="tools.php" class="brackets">Back to tools</a>
    </div>
    <table class="permission_head layout">
        <tr>
            <td class="label">Permission name</td>
            <td><input type="text" name="name" id="name" value="<?=!empty($Name) ? display_str($Name) : ''?>" /></td>
        </tr>
        <tr>
            <td class="label">Class level</td>
            <td><input type="text" name="level" id="level" value="<?=!empty($Level) ? display_str($Level) : ''?>" /></td>
        </tr>
        <tr>
            <td class="label">Secondary class</td>
            <td><input type="checkbox" name="secondary" value="1"<?=!empty($Secondary) ? ' checked="checked"' : ''?> /></td>
        </tr>
        <tr>
            <td class="label">Show on staff page</td>
            <td><input type="checkbox" name="displaystaff" value="1"<?=!empty($DisplayStaff) ? ' checked="checked"' : ''?> /></td>
        </tr>
        <tr>
            <td class="label">Staff page group</td>
            <td>
                <select name="staffgroup" id="staffgroup">
<?php
foreach ($Groups as $Group) { ?>
                    <option value="<?=$Group['ID']?>"<?=$Group['ID'] == $StaffGroup ? ' selected="selected"' : ''?>><?=$Group['Name']?></option>
<?php
} ?>
                </select>
            </td>
        </tr>
        <tr>
            <td class="label">Maximum number of personal collages</td>
            <td><input type="text" name="maxcollages" size="5" value="<?=$Values['MaxCollages']?>" /></td>
        </tr>
        <tr>
            <td class="label">Additional forums</td>
            <td><input type="text" size="30" name="forums" value="<?=display_str($Forums)?>" /></td>
        </tr>
<?php if (is_numeric($_REQUEST['id'])) { ?>
        <tr>
            <td class="label">Current users in this class</td>
            <td><?=number_format($UserCount)?></td>
        </tr>
<?php } ?>
    </table>
<?php
include(SERVER_ROOT."/classes/permissions_form.php");
permissions_form();
?>
</form>
<?php View::show_footer(); ?>
