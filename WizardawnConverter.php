<?php

namespace ssv_material_parser;

use \DOMDocument;

/**
 * Created by PhpStorm.
 * User: moridrin
 * Date: 14-6-17
 * Time: 7:15
 */
abstract class WizardawnConverter
{
    private static $buildings = array();
    private static $npcs = array();
    private static $createPosts = false;

    /**
     * This function converts a HTML string as generated by the Wizardawn Fantasy Settlements Generator to either MaterializeCSS compatible HTML blocks or directly into custom posts (you will need the mp-dd plugin for this).
     *
     * @param string $content
     * @param bool   $createPosts
     *
     * @return array
     */
    public static function Convert($content, $createPosts)
    {
        self::$createPosts = $createPosts;
        $content           = self::cleanCode($content);
        $content           = self::wizardawnBugfixes($content);
        $parts             = self::parseContent($content);

        foreach ($parts as $key => &$part) {
            switch ($key) {
                case 'map':
                    $part = self::parseMap($part);
                    break;
                case 'npcs':
                    $part = self::parseBuildings($part);
                    break;
                case 'guards':
                case 'churches':
                case 'banks':
                case 'merchants':
                case 'guilds':
                    $part = isset($parts['npcs']) ? self::appendToBuildings($part) : self::parseBuildings($part);
                    break;
            }
            $part = self::finalizePart($part);
        }

        foreach (self::$buildings as $id => &$building) {
            $building = self::finalizePart("<div id=\"modal_$id\" class=\"modal\"><div class=\"modal-content\">" . self::parseNPCs($building, $id) . "</div></div>");
        }
        $parts['buildings'] = self::finalizePart(implode('', self::$buildings));

        if (isset($parts['npcs'])) {
            $emptyHouses     = '';
            $filterBuildings = $parts;
            unset($filterBuildings['map']);
            unset($filterBuildings['npcs']);
            $fullHTML = self::cleanCode(implode('', $filterBuildings));
            if (preg_match_all("/.*?href=\"#modal_([0-9]+)\".*?<br\/>/", $parts['npcs'], $buildingSearces)) {
                for ($i = 0; $i < count($buildingSearces[0]); $i++) {
                    $search = $buildingSearces[1][$i];
                    if (strpos($fullHTML, "href=\"#modal_$search\"") === false) {
                        $emptyHouses .= $buildingSearces[0][$i];
                    }
                }
            }
            $parts['npcs'] = $emptyHouses;
        }

        return $parts;
    }

    /**
     * This function converts to a UTF-8 string, removes all redundant spaces, tabs, etc. and returns all usable code after the closing head tag.
     *
     * @param string $html
     *
     * @return string
     */
    private static function cleanCode($html)
    {
        $html = str_replace(Parser::REMOVE_HTML, '', $html);
        $html = preg_replace('!\s+!', ' ', $html);
        $html = iconv("UTF-8", "UTF-8//IGNORE", utf8_decode($html));
        $html = trim(preg_replace('/.*<\/head>/', '', $html));
        return $html;
    }

    /**
     * This function fixes all bugs in the original generated code from the generated Wizardawn HTML.
     *
     * @param string $content
     *
     * @return string
     */
    private static function wizardawnBugfixes($content)
    {
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML($content);
        $body         = $file->getElementsByTagName('body')->item(0);
        $baseElements = $body->childNodes;
        for ($i = 0; $i < $baseElements->length; $i++) {
            $html = $file->saveHTML($baseElements->item($i));
            if (strpos($html, 'wtown_01.jpg') !== false) {
                $badCode = trim($file->saveHTML($baseElements->item($i + 2)->childNodes->item(0)));
            }
        }
        if (isset($badCode)) {
            $html = $file->saveHTML();
            $html = str_replace($badCode, $badCode . '</font>', $html);
            $file->loadHTML($html);
        }
        return self::cleanCode($file->saveHTML());
    }

    /**
     * This function converts the raw HTML string to an array of raw HTML strings grouped by part.
     *
     * @param string $content
     *
     * @return string[]
     */
    private static function parseContent($content)
    {
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML($content);
        $body         = $file->getElementsByTagName('body')->item(0);
        $baseElements = $body->childNodes;

        $parts  = array();
        $filter = 'map';
        for ($i = 0; $i < $baseElements->length; $i++) {
            $baseElement = $baseElements->item($i);
            $html        = $file->saveHTML($baseElement);
            if ($filter == 'map' && strpos($html, '<hr>') !== false) {
                $filter = 'title';
                continue;
            }
            if (strpos($html, 'wtown_01.jpg') !== false) {
                $filter = 'npcs';
                continue;
            }
            if (strpos($html, 'wtown_02.jpg') !== false) {
                $filter = 'ruler';
                continue;
            }
            if (strpos($html, 'wtown_03.jpg') !== false) {
                $filter = 'guards';
                continue;
            }
            if (strpos($html, 'wtown_04.jpg') !== false) {
                $filter = 'churches';
                continue;
            }
            if (strpos($html, 'wtown_05.jpg') !== false) {
                $filter = 'banks';
                continue;
            }
            if (strpos($html, 'wtown_06.jpg') !== false) {
                $filter = 'merchants';
                continue;
            }
            if (strpos($html, 'wtown_07.jpg') !== false) {
                $filter = 'guilds';
                continue;
            }
            if (!isset($parts[$filter])) {
                $parts[$filter] = '';
            }
            $parts[$filter] .= trim($html);
        }
        return array_filter($parts);
    }

    /**
     * This function parses the Map and adds links to the modals.
     *
     * @param string $basePart
     *
     * @return string
     */
    private static function parseMap($basePart)
    {
        $part = self::cleanCode($basePart);
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML($part);

        $oldMap = $file->getElementById('myMap');
        if (preg_match("/width: ([0-9]+)px;/", $oldMap->getAttribute('style'), $mapWidth)) {
            $mapWidth = (($mapWidth[1] - 5) + 100);
        }
        $newMap = '<div style="overflow: auto;">';
        $newMap .= '<div style="width: ' . $mapWidth . 'px;">';
        for ($i = 0; $i < $oldMap->childNodes->length; $i++) {
            $column = $oldMap->childNodes->item($i);
            if (!($column instanceof \DOMElement)) {
                $column->parentNode->removeChild($column);
            }
        }
        for ($i = 0; $i < $oldMap->childNodes->length; $i++) {
            $column = $oldMap->childNodes->item($i);
            $column = preg_replace("/color: #FF0000;\">([0-9]+)<\/div>/", "color: #FF0000;\"><a href=\"#modal_$1\">$1</a></div>", self::cleanCode($file->saveHTML($column)));
            $column = preg_replace("/style=\"position:absolute;top:([0-9]+)px; left:([0-9]+)px; z-index:1;\"/", "style=\"display: inline-block; position:relative; padding: 0;\"", $column);
            $newMap .= $column;
        }

        return self::finalizePart($newMap . '</div></div>');
    }

    /**
     * This function parses the raw HTML code from a part, saves the buildings to the buildings array and returns the HTML code for the part with links to open the buildings as modals.
     *
     * @param string $basePart
     *
     * @return array
     */
    private static function parseBuildings($basePart)
    {
        $basePart  = preg_replace("/<font size=\"3\">([0-9]+)<\/font>/", "##START##$0", $basePart);
        $basePart  = str_replace(array('<b><i>', '</i></b>'), '', $basePart);
        $basePart  = str_replace('</i><b>', '</i>', $basePart);
        $buildings = preg_split("/##START##/", $basePart);
        $newParts  = array();
        foreach ($buildings as $building) {
            if (preg_match_all("/<font size=\"3\">([0-9]+)<\/font>/", $building, $ids)) {
                $id    = $ids[1][0];
                $title = "Building $id";
                if (preg_match("/-<b>(.*?)<\/b>/", $building, $titles)) {
                    // Citizens start with their name followed by a ':' where Merchants, Guilds, etc. just have the name (not followed by a ':').
                    if (!mp_ends_with($titles[1], ':') !== false) {
                        $title    = $titles[1] . " (Building $id)";
                        $building = trim(str_replace($titles[0], '', $building));
                    }
                }
                self::$buildings[$id] = preg_replace("/<font size=\"3\">$id<\/font>/", "<h1>$title</h1>", $building);
                $newParts[]           = "<a class=\"modal-trigger\" href=\"#modal_$id\">$title</a><br/>";
            }
        }

        // All Buildings end with a '<hr/>' except for the last building so we add this manually.
        self::$buildings[count(self::$buildings)] .= '<hr/>';

        return implode('', $newParts);
    }

    /**
     * @param string $basePart
     *
     * @return array
     */
    private static function appendToBuildings($basePart)
    {
        $basePart  = preg_replace("/<font size=\"3\">([0-9]+)<\/font>/", "##START##$0", $basePart);
        $basePart  = str_replace(array('<b><i>', '</i></b>'), '', $basePart);
        $basePart  = str_replace('</i><b>', '</i>', $basePart);
        $buildings = preg_split("/##START##/", $basePart);
        $newParts  = array();
        foreach ($buildings as $building) {
            if (preg_match("/<font size=\"3\">([0-9]+)<\/font>/", $building, $ids)
                && preg_match("/-<b>(.*?)<\/b>/", $building, $titles)
                && preg_match("/\[(.*?)\] <b>(.*?):<\/b>/", $building, $info)
            ) {
                $id         = $ids[1];
                $title      = $titles[1];
                $profession = $info[2];
                $info       = $info[1];

                $file = new DOMDocument();
                libxml_use_internal_errors(true);
                $file->loadHTML($building);
                $firstHR              = trim($file->saveHTML($file->getElementsByTagName('hr')->item(0)));
                $htmlParts            = explode($firstHR, $building);
                $htmlParts[0]         = "<h3><b>$profession</b> [$info]</h3>";
                $building             = trim(implode('<hr/>', $htmlParts));
                self::$buildings[$id] = str_replace("<h1>Building $id</h1>", "<h1>$title</h1>", self::$buildings[$id] . $building);
                $newParts[]           = "<a class=\"modal-trigger\" href=\"#modal_$id\">$title (Building $id)</a><br/>";
            }
        }
        return implode('', $newParts);
    }

    /**
     * This function fixes some last issues such as image URLs, '<font>' blocks are replaced with '<span>' blocks, etc.
     *
     * @param string $part
     *
     * @return string
     */
    private static function finalizePart($part)
    {
        $part = self::cleanCode($part);
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML($part);

        $images = $file->getElementsByTagName('img');
        foreach ($images as $image) {
            $imageStart = self::cleanCode($file->saveHTML($image));
            if (strpos($imageStart, 'wizardawn.and-mag.com') === false) {
                $imageNew = self::cleanCode(preg_replace('/.\/[\s\S]+?\//', 'http://wizardawn.and-mag.com/maps/', $imageStart));
                $part     = str_replace($imageStart, $imageNew, $part);
            }
        }
        $part = preg_replace("/<font.*?>(.*?)<\/font>/", "<span>$1</span>", $part);
        return self::cleanCode($part);
    }

    /**
     * This function parses the NPCs out of the building formats them and puts them back in in the new format.
     *
     * @param string $building
     *
     * @return string
     */
    private static function parseNPCs($building, $buildingID)
    {
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML(utf8_decode($building));
        $html = self::cleanCode($file->saveHTML());
        if (preg_match("/<h1>(.*?)<\/h1>/", $html, $title)) {
            $title = $title[0];
        }
        if (strpos($building, 'This building is empty') !== false) {
            return self::cleanCode("$title <p>This building is empty.</p>");
        }

        if (preg_match("/<font size=\"2\">-<b>(.*?)<\/font>/", $html, $owner)) {
            $html  = str_replace($owner[0], '###OWNER_PLACEHOLDER###', $html);
            $owner = self::parseNPC($owner[0], $buildingID);
            $html  = str_replace('###OWNER_PLACEHOLDER###', self::npcToHTML($owner, false), $html);
            if (preg_match("/<font size=\"2\">--<b>(.*?)<\/font>/", $html, $spouse)) {
                $html   = str_replace($spouse[0], '###SPOUSE_PLACEHOLDER###', $html);
                $spouse = self::parseNPC($spouse[0], $buildingID);
                self::updateNPC($owner, 'spouse', $spouse);
                $html = str_replace('###SPOUSE_PLACEHOLDER###', self::npcToHTML($spouse, false, ' (spouse)'), $html);
            }
            if (preg_match_all("/<font size=\"2\">---<b>(.*?)<\/font>/", $html, $children)) {
                for ($i = 0; $i < count($children[0]); $i++) {
                    $html  = str_replace($children[0][$i], '###CHILD_' . $i . '_PLACEHOLDER###', $html);
                    $child = self::parseNPC($children[0][$i], $buildingID);
                    self::updateNPC($owner, 'child', $child);
                    $html = str_replace('###CHILD_' . $i . '_PLACEHOLDER###', self::npcToHTML($child, false, ' (child)'), $html);
                }
            }
            if (preg_match("/<h3>(.*?)<\/h3>/", $html, $other)) {
                if (preg_match("/\[(.*?)\]/", $html, $prefessionInfo)) {
                    if (self::$createPosts) {
                        self::updateNPC($owner, 'profession_info', $prefessionInfo[1]);
                    }
                }
                if (preg_match("/<b>(.*?)<\/b>/", $html, $profession)) {
                    if (self::$createPosts) {
                        self::updateNPC($owner, 'profession', $profession[1]);
                    }
                }
            }
        } elseif (preg_match("/<font size=\"2\">(.*?)<\/font>/", $html, $owner)) {
            $owner          = $owner[0];
            $prefessionInfo = 0;
            $profession     = '';
            if (preg_match("/ \[(.*?)\]/", $owner, $prefessionInfo)) {
                $owner          = str_replace($prefessionInfo[0], '', $owner);
                $prefessionInfo = $prefessionInfo[1];
            }
            if (preg_match("/ <b>(.*?)<\/b> /", $owner, $profession)) {
                $owner      = str_replace($profession[0], '-', $owner);
                $profession = $profession[1];
            }
            $owner = self::parseNPC($owner, $buildingID);
            self::updateNPC($owner, 'profession', $profession);
            self::updateNPC($owner, 'profession_info', $prefessionInfo);
        }

        if (self::$createPosts) {
            wp_insert_post(
                array(
                    'post_title'   => $title,
                    'post_content' => self::finalizePart($html),
                    'post_type'    => 'buildings',
                    'post_status'  => 'publish',
                )
            );
        }

        return $html;
    }

    /**
     * @param string $npcHTML
     * @param int    $buildingID
     *
     * @return array|int the NPC (or its ID)
     */
    private static function parseNPC($npcHTML, $buildingID)
    {
        $npc = array(
            'name'        => '',
            'height'      => '',
            'weight'      => '',
            'description' => '',
            'spouse'      => '',
            'children'    => array(),
            'clothing'    => array(),
            'possessions' => array(),
            'building'    => $buildingID,
        );
        if (preg_match("/<font size=\"2\">-{1,}<b>(.*?):<\/b>/", $npcHTML, $name)) {
            $npcHTML     = str_replace($name[0], '', $npcHTML);
            $npc['name'] = $name[1];
        }
        if (preg_match("/\[<b>HGT:<\/b>(.*?)<b>WGT:<\/b>(.*?)\]/", $npcHTML, $physique)) {
            $npcHTML = str_replace($physique[0], '', $npcHTML);
            $height  = 0;
            $weight  = 0;
            if (preg_match("/(.*?)ft/", $physique[1], $feet)) {
                $height += intval($feet[1]) * 30.48;
            }
            if (preg_match("/, (.*?)in/", $physique[1], $inches)) {
                $height += intval($inches[1]) * 2.54;
            }
            if (preg_match("/(.*?)lbs/", $physique[2], $pounds)) {
                $weight = intval($pounds[1]) * 0.453592;
            }
            $npc['height'] = intval(round($height, 0));
            $npc['weight'] = intval(round($weight, 0));
        }
        if (preg_match("/<b>DRESSEDIN:<\/b>(.*?)\./", $npcHTML, $clothing)) {
            $npcHTML         = str_replace($clothing[0], '', $npcHTML);
            $npc['clothing'] = explode(', ', $clothing[1]);
            foreach ($npc['clothing'] as &$item) {
                if (mp_starts_with(trim($item), 'and')) {
                    $item = substr(trim($item), 3);
                }
                $item = ucfirst(trim($item));
            }
        }
        if (preg_match("/<b>POSSESSIONS:<\/b>(.*?)\./", $npcHTML, $possessions)) {
            $npcHTML            = str_replace($possessions[0], '', $npcHTML);
            $npc['possessions'] = explode(', ', $possessions[1]);
            foreach ($npc['possessions'] as &$item) {
                if (mp_starts_with(trim($item), 'and')) {
                    $item = substr(trim($item), 3);
                }
                $item = ucfirst(trim($item));
            }
        }

        $description = trim(str_replace(array('</font>', '<hr color="#C0C0C0" size="1">'), '', $npcHTML));
        if (self::$createPosts) {
            $npcID = wp_insert_post(
                array(
                    'post_title'   => $npc['name'],
                    'post_content' => self::finalizePart($description),
                    'post_type'    => 'npcs',
                    'post_status'  => 'publish',
                )
            );
            foreach ($npc as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                update_post_meta($npcID, $key, $value);
            }
        } else {
            $npc['description'] = $description;
            $npcID              = count(self::$npcs);
        }
        self::$npcs[$npcID] = $npc;
        return $npcID;
    }

    /**
     * This function updates an NPC based on if the NPC is created as a post or if it is saved as an array.
     *
     * @param int|array $npc if createPost = true it will be an int otherwise it will be the array of NPCs.
     * @param string    $key
     * @param mixed     $value
     */
    private static function updateNPC(&$npc, $key, $value)
    {
        if (self::$createPosts) {
            if ($key == 'spouse') {
                self::updateFamilyLinks($npc, 0, $value);
            } elseif ($key == 'child') {
                self::updateFamilyLinks($npc, 1, $value);
            } else {
                update_post_meta($npc, $key, $value);
            }
        } else {
            if ($key == 'child') {
                self::$npcs[$npc]['children'][] = $value;
            } else {
                self::$npcs[$npc][$key] = $value;
            }
        }
    }

    /**
     * This function adds a family link to an NPC (if createPosts is true).
     *
     * @param int $npc
     * @param int $linkType 0 for spouse and 1 for child
     * @param int $npcID
     */
    private static function updateFamilyLinks($npc, $linkType, $npcID)
    {
        if (!self::$createPosts) {
            return;
        }
        $familyLinks = get_post_meta($npc, 'family_links', true);
        if (!is_array($familyLinks)) {
            $familyLinks = array();
        }
        $familyLinks[] = array('link_type' => $linkType, 'npc_id' => $npcID);
        update_post_meta($npc, 'family_links', $familyLinks);
    }

    /**
     * @param int    $npc        if createPost = true it will be an int otherwise it will be the array of NPCs.
     * @param bool   $withFamily set to false if you don't want to show the spouse and or children if any.
     * @param string $familyDefinition can be set to append for example ' (spouse)' to the name.
     *
     * @return string with either the HTML of the NPC or a TAG for a WordPress post to include the NPC.
     */
    private static function npcToHTML($npc, $withFamily = true, $familyDefinition = '')
    {
        if (self::$createPosts) {
            return "[npc-$npc]";
        }
        if (is_numeric($npc)) {
            $npc = self::$npcs[$npc];
        }
        $html = '<h3>' . $npc['name'] . $familyDefinition . '</h3>';
        $html .= '<p>';
        $html .= '<b>Height:</b> ' . $npc['height'] . ' <b>Weight:</b> ' . $npc['weight'] . '<br/>';
        $html .= $npc['description'] . '<br/>';
        $html .= '<b>Wearing:</b> ' . implode(', ', $npc['clothing']) . '<br/>';
        $html .= '<b>Possessions:</b> ' . implode(', ', $npc['possessions']) . '<br/>';
        $html .= '</p>';
        if ($withFamily) {
            if (!empty($npc['spouse'])) {
                $spouse = self::$npcs[$npc['spouse']];
                $html   .= '<h3>' . $spouse['name'] . ' (spouse)</h3>';
                $html   .= '<p>';
                $html   .= '<b>Height:</b> ' . $spouse['height'] . ' <b>Weight:</b> ' . $spouse['weight'] . '<br/>';
                $html   .= $spouse['description'] . '<br/>';
                $html   .= '<b>Wearing:</b> ' . implode(', ', $spouse['clothing']) . '<br/>';
                $html   .= '<b>Possessions:</b> ' . implode(', ', $spouse['possessions']) . '<br/>';
                $html   .= '</p>';
            }
            foreach ($npc['children'] as $child) {
                $child = self::$npcs[$child];
                $html  .= '<h3>' . $child['name'] . ' (child)</h3>';
                $html  .= '<p>';
                $html  .= '<b>Height:</b> ' . $child['height'] . ' <b>Weight:</b> ' . $child['weight'] . '<br/>';
                $html  .= $child['description'] . '<br/>';
                $html  .= '<b>Wearing:</b> ' . implode(', ', $child['clothing']) . '<br/>';
                $html  .= '<b>Possessions:</b> ' . implode(', ', $child['possessions']) . '<br/>';
                $html  .= '</p>';
            }
        }
        return self::cleanCode($html);
    }
}
