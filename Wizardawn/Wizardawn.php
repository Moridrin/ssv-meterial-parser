<?php

namespace ssv_material_parser;

use Exception;
use Wizardawn\Models\Building;
use Wizardawn\Models\City;
use Wizardawn\Models\NPC;

require_once 'Converter.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    ?>
    <form action="#" method="post" enctype="multipart/form-data">
        <input type="hidden" name="save" value="upload">
        <input type="file" name="html_file"><br/>
        <select name="parse_output">
            <option value="mp_dd">D&D Objects</option>
            <option value="html">HTML</option>
        </select><br/>
        <input type="submit" value="Upload" name="submit">
        <input type="submit" value="Test" name="submit">
    </form>
    <?php
} else {
    $nextPage = '';
    if (!isset($_POST['save'])) {
        mp_var_export($_POST, 1);
    }
    switch ($_POST['save']) {
        case 'upload':
            $nextPage = 'npcs';
            if ($_POST['submit'] == 'Test') {
                $fileContent = file_get_html(Parser::URL . 'test/001.html');
            } else {
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                $uploadedFile    = $_FILES['html_file'];
                $uploadOverrides = array('test_form' => false);
                $movedFile       = wp_handle_upload($uploadedFile, $uploadOverrides);
                if (!$movedFile || isset($movedFile['error']) || $movedFile['type'] != 'text/html') {
                    echo $movedFile['error'];
                    return;
                }
                $fileContent = file_get_html($movedFile['file']);
            }
            $city             = Converter::Convert($fileContent);
            $_SESSION['city'] = $city;
            if ($_POST['parse_output'] == 'html') {
                ?><textarea><?= $city->getHTML() ?></textarea><?php
            }
            break;
        case 'npcs':
            if (isset($_POST['skip'])) {
                $nextPage = 'buildings';
                break;
            }
            /** @var City $city */
            $city = $_SESSION['city'];
            if (isset($_POST['save_single'])) {
                $nextPage = 'npcs';
                $id = $_POST['save_single'];
                $wp_id = NPC::getFromPOST($id, true)->toWordPress();
                $tmp = $city->replaceID($id, $wp_id);
                if (!$tmp) {
                    throw new Exception('WordPress ID not changed in City Object');
                }
                $_SESSION['city'] = $city;
            } else {
                $nextPage = 'buildings';
                $keyReplaceMap = [];
                foreach ($_POST['npc___save'] as $id) {
                    $keyReplaceMap[$id] = NPC::getFromPOST($id)->toWordPress();
                }
                mp_var_export($keyReplaceMap, true);
            }
            break;
        case 'buildings':
            $nextPage = 'city';
            break;
        case 'city':
            break;
    }

    switch ($nextPage) {
        case 'npcs':
            $city = $_SESSION['city'];
            ?>
            <form action="#" method="POST">
                <input type="submit" name="skip" id="submit" class="button button-primary button-large" value="Go to Buildings"><br/>
                <input type="hidden" name="save" value="npcs">
                <?php
                foreach ($city->getBuildings() as $key => $building) {
                    if ($building instanceof Building) {
                        foreach ($building->getNPCs() as $npc) {
                            if ($npc instanceof NPC) {
                                echo $npc->getHTML();
                            }
                        }
                    }
                }
                echo get_submit_button('Save NPCs');
                ?>
            </form>
            <?php
            break;
        case 'buildings':
            $city = $_SESSION['city'];
            ?>
            <form action="#" method="POST">
                <?php
                foreach ($city->getBuildings() as $key => $building) {
                    if ($building instanceof Building) {
                        echo $building->getHTML();
                    }
                }
                echo get_submit_button('Save buildings');
                ?>
                <input type="hidden" name="next_page" value="buildings">
            </form>
            <?php
            break;
    }
}
