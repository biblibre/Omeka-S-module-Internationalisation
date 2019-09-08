<?php
namespace LanguageSwitcher;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
       ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl
            ->allow(
                null,
                [\LanguageSwitcher\Api\Adapter\SitePageRelationAdapter::class],
                ['search', 'read']
            );
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $vendor = __DIR__ . '/vendor/daniel-km/simple-iso-639-3/src/Iso639p3.php';
        if (!file_exists($vendor)) {
            $t = $serviceLocator->get('MvcTranslator');
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                $t->translate('The composer vendor is not ready.') // @translate
                . ' ' . $t->translate('See module’s installation documentation.') // @translate
            );
        }

        parent::install($serviceLocator);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'handleViewLayoutPublic']
        );

        // Add the related pages to the representation of the pages.
        $sharedEventManager->attach(
            \Omeka\Api\Representation\SitePageRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLd']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\SitePageAdapter::class,
            'api.update.post',
            [$this, 'handleApiUpdatePostPage']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_input_filters',
            [$this, 'handleMainSettingsFilters']
        );
    }

    public function handleViewLayoutPublic(Event $event)
    {
        $view = $event->getTarget();
        if (!$view->status()->isSiteRequest()) {
            return;
        }

        $assetUrl = $view->getHelperPluginManager()->get('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/language-switcher.css', 'LanguageSwitcher'))
            ->appendStylesheet($assetUrl('vendor/flag-icon-css/css/flag-icon.min.css', 'LanguageSwitcher'));
    }

    public function filterJsonLd(Event $event)
    {
        $page = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $pageId = $page->id();
        $relations = $api
            ->search(
                'site_page_relations',
                ['relation' => $pageId]
            )
            ->getContent();
        $relations = array_map(function ($relation) use ($pageId) {
            $related = $relation->relatedPage();
            return $pageId === $related->id()
                ? $relation->page()->getReference()
                : $related->getReference();
        }, $relations);
        $jsonLd['o-module-language-switcher:related_page'] = $relations;
        $event->setParam('jsonLd', $jsonLd);
    }

    public function handleApiUpdatePostPage(Event $event)
    {
        $services = $this->getServiceLocator();
        /**
         * @var \Doctrine\DBAL\Connection $connection
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Api\Request $request
         */
        $connection = $services->get('Omeka\Connection');
        $api = $services->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $pageId = $response->getContent()->getId();

        $selected = $request->getValue('o-module-language-switcher:related_page', []);
        $selected = array_map('intval', $selected);

        // The page cannot be related to itself.
        $key = array_search($pageId, $selected);
        if ($key !== false) {
            unset($selected[$key]);
        }

        // To simplify process, all existing pairs are deleted before saving.

        // Direct query is used because the visibility don't need to be checked:
        // it is done when the page is loaded and even hidden, the relation
        // should remain.
        // TODO Check if this process remove hidden pages in true life (with language switcher, the user should see all localized sites).

        $existing = $api
            ->search(
                'site_page_relations',
                ['relation' => $pageId]
            )
            ->getContent();
        $existingIds = array_map(function ($relation) use ($pageId) {
            $relatedId = $relation->relatedPage()->id();
            return $pageId === $relatedId
                ? $relation->page()->id()
                : $relatedId;
        }, $existing);

        if (count($existingIds)) {
            $sql = <<<SQL
DELETE FROM site_page_relation
WHERE page_id IN (:page_ids) OR related_page_id IN (:page_ids)
SQL;
            $connection->executeQuery($sql, ['page_ids' => $existingIds], ['page_ids' => $connection::PARAM_INT_ARRAY]);
        }

        if (empty($selected)) {
            return;
        }

        // Add all pairs.
        $sql = <<<SQL
INSERT INTO site_page_relation
VALUES
SQL;

        $ids = $selected;
        $ids[] = $pageId;
        sort($ids);
        $relatedIds = $ids;
        foreach ($ids as $id) {
            foreach ($relatedIds as $relatedId) {
                if ($relatedId > $id) {
                    $sql .= "\n($id, $relatedId),";
                }
            }
        }
        $sql = rtrim($sql, ',');
        $connection->exec($sql);
    }

    public function handleMainSettings(Event $event)
    {
        parent::handleMainSettings($event);

        $services = $this->getServiceLocator();

        $space = strtolower(__NAMESPACE__);

        $api = $services->get('Omeka\ApiManager');
        $sites = $api
            ->search('sites', ['sort_by' => 'slug', 'sort_order' => 'asc'], ['returnScalar' => 'slug'])
            ->getContent();

        $siteGroups = $this->listSiteGroups();
        $siteGroupsString = '';
        foreach ($siteGroups as $group) {
            if ($group) {
                $siteGroupsString .= implode(' ', $group) . "\n";
            }
        }
        $siteGroupsString = trim($siteGroupsString);

        /**
         * @var \Omeka\Form\Element\RestoreTextarea $siteGroupsElement
         * @var \LanguageSwitcher\Form\SettingsFieldset $fieldset
         */
        $form = $event->getTarget();
        $fieldset = $form->get($space);
        $siteGroupsElement = $fieldset
            ->get('languageswitcher_site_groups');
        $siteGroupsElement
            ->setValue($siteGroupsString)
            ->setRestoreButtonText('Remove all groups') // @translate
            ->setRestoreValue(implode("\n", $sites));
    }

    public function handleMainSettingsFilters(Event $event)
    {
        $event->getParam('inputFilter')
            ->get('languageswitcher')
            ->add([
                'name' => 'languageswitcher_site_groups',
                'required' => false,
                'filters' => [
                    [
                        'name' => \Zend\Filter\Callback::class,
                        'options' => [
                            'callback' => [$this, 'filterSiteGroups'],
                        ],
                    ],
                ],
            ]);
    }

    public function filterSiteGroups($groups)
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        $siteList = [];

        $sites = $api
            ->search('sites', ['sort_by' => 'slug', 'sort_order' => 'asc'], ['returnScalar' => 'slug'])
            ->getContent();
        $sites = array_combine($sites, $sites);

        $groups = str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $groups);
        $groups = array_filter(array_map('trim', explode("\n", $groups)));
        foreach ($groups as $group) {
            $group = array_unique(array_filter(array_map('trim', explode(' ', str_replace(',', ' ', $group)))));
            $group = array_intersect($group, $sites);
            if (count($group) > 1) {
                sort($group, SORT_NATURAL);
                foreach ($group as $site) {
                    $siteList[$site] = $group;
                    unset($sites[$site]);
                }
            }
        }

        ksort($siteList, SORT_NATURAL);
        return $siteList;
    }

    /**
     * Clean and list groups, even with one site.
     *
     * @return array[]
     */
    protected function listSiteGroups()
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        $siteGroups = $settings->get('languageswitcher_site_groups') ?: [];

        $sites = $api
            ->search('sites', ['sort_by' => 'slug', 'sort_order' => 'asc'], ['returnScalar' => 'slug'])
            ->getContent();
        $sites = array_combine($sites, $sites);

        // Clean sites.
        ksort($siteGroups, SORT_NATURAL);
        $siteGroups = array_filter(array_map(function($group) use ($sites) {
            $v = array_intersect($group, $sites);
            if (count($v) <= 1) {
                return [];
            }
            sort($v, SORT_NATURAL);
            return $v;
        }, array_intersect_key($siteGroups, $sites)));

        // Remove sites that belongs to a group and append them.
        $remaining = array_map(function($site) {
            return [$site];
        }, $sites);

        $result = $siteGroups;
        foreach ($result as $site => $group) {
            unset($remaining[$site]);
            $remaining = array_diff_key($remaining, array_flip($group));
            if (isset($siteGroups[$site])) {
                foreach ($group as $siteInGroup) {
                    if ($siteInGroup !== $site) {
                        unset($siteGroups[$siteInGroup]);
                    }
                }
            }
        }

        return $siteGroups + $remaining;
    }
}
