<?php

require_once(TOOLKIT . '/class.datasource.php');
require_once(TOOLKIT . '/data-sources/class.datasource.navigation.php');

class MultilingualNavigationDatasource extends NavigationDatasource
{
    public function buildMultilingualPageXML($page, $page_types, $qf)
    {
        $lang_code = FLang::getLangCode();

        $oPage = new XMLElement('page');
        $oPage->setAttribute('handle', $page['handle']);
        $oPage->setAttribute('id', $page['id']);
        $oPage->appendChild(new XMLElement('name', General::sanitize($page['title'])));
        // keep current first
        $oPage->appendChild(new XMLElement(
            'item',
            General::sanitize($page['plh_t-'.$lang_code]),
            array(
                'lang' => $lang_code,
                'handle' => $page['plh_h-'.$lang_code],
            )
        ));

        // add others
        foreach( FLang::getLangs() as $lc ){
            if($lang_code != $lc) {
                $oPage->appendChild(new XMLElement(
                    'item',
                    General::sanitize($page['plh_t-'.$lc]),
                    array(
                        'lang' => $lc,
                        'handle' => $page['plh_h-'.$lc],
                    )
                ));
            }
        }

        if(in_array($page['id'], array_keys($page_types))) {
            $xTypes = new XMLElement('types');
            foreach($page_types[$page['id']] as $type) {
                $xTypes->appendChild(new XMLElement('type', $type));
            }
            $oPage->appendChild($xTypes);
        }

        if($page['children'] != '0') {
            if(
                $children = (new PageManager)
                    ->select(['id', 'handle', 'title'])
                    ->projection($qf)
                    ->where(['parent' => $page['id']])
                    ->execute()
                    ->rows()
            ) {
                foreach ($children as $c) {
                    $oPage->appendChild($this->buildMultilingualPageXML($c, $page_types, $qf));
                }
            }
        }

        return $oPage;
    }

    public function execute(array &$param_pool = null)
    {
        $result = new XMLElement($this->dsParamROOTELEMENT);
        $stm = Symphony::Database()
            ->select(['p.id', 'p.title', 'p.handle', 'p.sortorder'])
            ->distinct()
            ->from('tbl_pages', 'p')
            ->orderBy('p.sortorder', 'ASC');
        $stm->projection([
            'children' => $stm
                ->select(['COUNT(id)'])
                ->from('tbl_pages')
                ->where(['parent' => '$p.id'])
        ]);
        $stm->leftJoin('tbl_pages_types', 'pt')
            ->on(['p.id' => '$pt.page_id']);

        if (trim($this->dsParamFILTERS['type']) != '') {
            $this->processNavigationTypeFilter($this->dsParamFILTERS['type'], $stm);
         }

        if (trim($this->dsParamFILTERS['parent']) != '') {
            $this->processNavigationParentFilter($this->dsParamFILTERS['parent'], $stm);
        } else {
            $stm->where(['parent' => null]);
         }

        $qf = [];

        foreach (FLang::getLangs() as $lc) {
            $qf = array_merge($qf, ["plh_t-{$lc}", "plh_h-{$lc}"]);
        }
        $stm->projection($qf);

        $pages = $stm->execute()->rows();

        if (empty($pages)) {
            if ($this->dsParamREDIRECTONEMPTY == 'yes') {
                throw new FrontendPageNotFoundException;
            }
            $result->appendChild($this->noRecordsFound());
        } else {
            // Build an array of all the types so that the page's don't have to do
            // individual lookups.
            $page_types = PageManager::fetchAllPagesPageTypes();

            foreach($pages as $page) {
                $result->appendChild($this->buildMultilingualPageXML($page, $page_types, $qf));
            }
        }

        return $result;
    }
}
