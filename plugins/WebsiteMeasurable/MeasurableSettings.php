<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\WebsiteMeasurable;
use Piwik\IP;
use Piwik\Network\IPUtils;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Plugins\WebsiteMeasurable\Settings\Urls;
use Piwik\Settings\Setting;
use Piwik\Settings\FieldConfig;
use Piwik\Plugins\SitesManager;
use Exception;
use Piwik\Url;

/**
 * Defines Settings for ExampleSettingsPlugin.
 *
 * Usage like this:
 * $settings = new MeasurableSettings($idSite);
 * $settings->autoRefresh->getValue();
 * $settings->metric->getValue();
 */
class MeasurableSettings extends \Piwik\Settings\Measurable\MeasurableSettings
{
    /** @var Setting */
    public $urls;

    /** @var Setting */
    public $onlyTrackWhitelstedUrls;

    /** @var Setting */
    public $keepPageUrlFragments;

    /** @var Setting */
    public $excludeKnownUrls;

    /** @var Setting */
    public $excludedUserAgents;

    /** @var Setting */
    public $excludedIps;

    /** @var Setting */
    public $siteSearch;

    /** @var Setting */
    public $useDefaultSiteSearchParams;

    /** @var Setting */
    public $siteSearchKeywords;

    /** @var Setting */
    public $siteSearchCategory;

    /** @var Setting */
    public $excludedParameters;

    /** @var Setting */
    public $ecommerce;

    /**
     * @var SitesManager\API
     */
    private $sitesManagerApi;

    /**
     * @var Plugin\Manager
     */
    private $pluginManager;

    public function __construct(SitesManager\API $api, Plugin\Manager $pluginManager, $idSite, $idMeasurableType)
    {
        $this->sitesManagerApi = $api;
        $this->pluginManager = $pluginManager;

        parent::__construct($idSite, $idMeasurableType);
    }

    protected function init()
    {
        $this->urls = new Urls($this->idSite);
        $this->addSetting($this->urls);

        $this->excludeKnownUrls = $this->makeExcludeUnknownUrls();
        $this->keepPageUrlFragments = $this->makeKeepUrlFragments($this->sitesManagerApi);
        $this->excludedIps = $this->makeExcludeIps();
        $this->excludedParameters = $this->makeExcludedParameters();
        $this->excludedUserAgents = $this->makeExcludedUserAgents();

        /**
         * SiteSearch
         */
        $this->siteSearch = $this->makeSiteSearch();
        $this->useDefaultSiteSearchParams = $this->makeUseDefaultSiteSearchParams($this->sitesManagerApi);
        $this->siteSearchKeywords = $this->makeSiteSearchKeywords();

        $siteSearchKeywords = $this->siteSearchKeywords->getValue();
        $this->useDefaultSiteSearchParams->setDefaultValue(empty($siteSearchKeywords));

        $this->siteSearchCategory = $this->makeSiteSearchCategory($this->pluginManager);
        /**
         * SiteSearch End
         */

        $this->ecommerce = $this->makeEcommerce();
    }

    private function makeExcludeUnknownUrls()
    {
        return $this->makeProperty('exclude_unknown_urls', $default = false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = Piwik::translate('SitesManager_OnlyMatchedUrlsAllowed');
            $field->inlineHelp = Piwik::translate('SitesManager_OnlyMatchedUrlsAllowedHelp')
                . '<br />'
                . Piwik::translate('SitesManager_OnlyMatchedUrlsAllowedHelpExamples');
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
        });
    }

    private function makeKeepUrlFragments(SitesManager\API $sitesManagerApi)
    {
        return $this->makeProperty('keep_url_fragment', $default = '0', FieldConfig::TYPE_STRING, function (FieldConfig $field) use ($sitesManagerApi) {
            $field->title = Piwik::translate('SitesManager_KeepURLFragmentsLong');
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;

            if ($sitesManagerApi->getKeepURLFragmentsGlobal()) {
                $default = Piwik::translate('General_Yes');
            } else {
                $default = Piwik::translate('General_No');
            }

            $field->availableValues = array(
                '0' => $default . ' (' . Piwik::translate('General_Default') . ')',
                '1' => Piwik::translate('General_Yes'),
                '2' => Piwik::translate('General_No')
            );
        });
    }

    private function makeExcludeIps()
    {
        return $this->makeProperty('excluded_ips', $default = array(), FieldConfig::TYPE_ARRAY, function (FieldConfig $field) {
            $ip = IP::getIpFromHeader();

            $field->title = Piwik::translate('SitesManager_ExcludedIps');
            $field->inlineHelp = Piwik::translate('SitesManager_HelpExcludedIps', array('1.2.3.*', '1.2.*.*'))
                . '<br /><br />'
                . Piwik::translate('SitesManager_YourCurrentIpAddressIs', array('<i>' . $ip . '</i>'));
            $field->uiControl = FieldConfig::UI_CONTROL_TEXTAREA;
            $field->uiControlAttributes = array('cols' => '20', 'rows' => '4');

            $field->validate = function ($value) {
                if (!empty($value)) {
                    $ips = array_map('trim', $value);
                    $ips = array_filter($ips, 'strlen');

                    foreach ($ips as $ip) {
                        if (IPUtils::getIPRangeBounds($ip) === null) {
                            throw new Exception(Piwik::translate('SitesManager_ExceptionInvalidIPFormat', array($ip, "1.2.3.4, 1.2.3.*, or 1.2.3.4/5")));
                        }
                    }
                }
            };
            $field->transform = function ($value) {
                if (empty($value)) {
                    return array();
                }

                $ips = array_map('trim', $value);
                $ips = array_filter($ips, 'strlen');
                return $ips;
            };
        });
    }

    private function makeExcludedParameters()
    {
        $self = $this;
        return $this->makeProperty('excluded_parameters', $default = array(), FieldConfig::TYPE_ARRAY, function (FieldConfig $field) use ($self) {
            $field->title = Piwik::translate('SitesManager_ExcludedParameters');
            $field->inlineHelp = Piwik::translate('SitesManager_ListOfQueryParametersToExclude', "/^sess.*|.*[dD]ate$/")
                . '<br /><br />'
                . Piwik::translate('SitesManager_PiwikWillAutomaticallyExcludeCommonSessionParameters', array('phpsessid, sessionid, ...'));
            $field->uiControl = FieldConfig::UI_CONTROL_TEXTAREA;
            $field->uiControlAttributes = array('cols' => '20', 'rows' => '4');
            $field->transform = function ($value) use ($self) {
                return $self->checkAndReturnCommaSeparatedStringList($value);
            };
        });
    }

    private function makeExcludedUserAgents()
    {
        $self = $this;
        return $this->makeProperty('excluded_user_agents', $default = array(), FieldConfig::TYPE_ARRAY, function (FieldConfig $field) use ($self) {
            $field->title = Piwik::translate('SitesManager_ExcludedUserAgents');
            $field->inlineHelp = Piwik::translate('SitesManager_GlobalExcludedUserAgentHelp1')
                . '<br /><br />'
                . Piwik::translate('SitesManager_GlobalListExcludedUserAgents_Desc')
                . '<br />'
                . Piwik::translate('SitesManager_GlobalExcludedUserAgentHelp2');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXTAREA;
            $field->uiControlAttributes = array('cols' => '20', 'rows' => '4');
            $field->transform = function ($value) use ($self) {
                return $self->checkAndReturnCommaSeparatedStringList($value);
            };
        });
    }

    private function makeSiteSearch()
    {
        return $this->makeProperty('sitesearch', $default = 1, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = Piwik::translate('Actions_SubmenuSitesearch');
            $field->inlineHelp = Piwik::translate('SitesManager_SiteSearchUse');
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
            $field->availableValues = array(
                1 => Piwik::translate('SitesManager_EnableSiteSearch'),
                0 => Piwik::translate('SitesManager_DisableSiteSearch')
            );
        });
    }

    private function makeUseDefaultSiteSearchParams(SitesManager\API $sitesManagerApi)
    {
        return $this->makeSetting('use_default_site_search_params', $default = true, FieldConfig::TYPE_BOOL, function (FieldConfig $field) use ($sitesManagerApi) {

            if (Piwik::hasUserSuperUserAccess()) {
                $title = Piwik::translate('SitesManager_SearchUseDefault', array("<a href='#globalSettings'>","</a>"));
            } else {
                $title = Piwik::translate('SitesManager_SearchUseDefault', array('', ''));
            }

            $field->title = $title;
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;

            $searchKeywordsGlobal = $sitesManagerApi->getSearchKeywordParametersGlobal();

            $hasParams = (int) !empty($searchKeywordsGlobal);
            $field->condition = $hasParams . ' && sitesearch';

            $searchKeywordsGlobal = $sitesManagerApi->getSearchKeywordParametersGlobal();
            $searchCategoryGlobal = $sitesManagerApi->getSearchCategoryParametersGlobal();

            $field->description  = Piwik::translate('SitesManager_SearchKeywordLabel');
            $field->description .= ' (' . Piwik::translate('General_Default') . ')';
            $field->description .= ': ';
            $field->description .= $searchKeywordsGlobal;
            $field->description .= ' & ';
            $field->description .= Piwik::translate('SitesManager_SearchCategoryLabel');
            $field->description .= ': ';
            $field->description .= $searchCategoryGlobal;
            $field->transform = function () {
                return null;// never actually save a value for this
            };
        });
    }

    private function makeSiteSearchKeywords()
    {
        return $this->makeProperty('sitesearch_keyword_parameters', $default = array(), FieldConfig::TYPE_ARRAY, function (FieldConfig $field) {
            $field->title = Piwik::translate('SitesManager_SearchKeywordLabel');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->inlineHelp = Piwik::translate('SitesManager_SearchKeywordParametersDesc');
            $field->condition = Piwik::translate('sitesearch && !use_default_site_search_params');
        });
    }

    private function makeSiteSearchCategory(Plugin\Manager $pluginManager)
    {
        return $this->makeProperty('sitesearch_category_parameters', $default = array(), FieldConfig::TYPE_ARRAY, function (FieldConfig $field) use ($pluginManager) {
            $field->title = Piwik::translate('SitesManager_SearchCategoryLabel');
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->inlineHelp = Piwik::translate('Goals_Optional')
                . '<br /><br />'
                . Piwik::translate('SitesManager_SearchCategoryParametersDesc');

            $hasCustomVars = (int) $pluginManager->isPluginActivated('CustomVariables');
            $field->condition = $hasCustomVars . ' && sitesearch && !use_default_site_search_params';
        });
    }

    private function makeEcommerce()
    {
        return $this->makeProperty('ecommerce', $default = 0, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = Piwik::translate('Goals_Ecommerce');
            $field->inlineHelp = Piwik::translate('SitesManager_EcommerceHelp')
                . '<br />'
                . Piwik::translate('SitesManager_PiwikOffersEcommerceAnalytics',
                    array("<a href='http://piwik.org/docs/ecommerce-analytics/' target='_blank'>", '</a>'));
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
            $field->availableValues = array(
                0 => Piwik::translate('SitesManager_NotAnEcommerceSite'),
                1 => Piwik::translate('SitesManager_EnableEcommerce')
            );
        });
    }

    public function checkAndReturnCommaSeparatedStringList($parameters)
    {
        if (empty($parameters)) {
            return array();
        }

        $parameters = array_map('trim', $parameters);
        $parameters = array_filter($parameters, 'strlen');
        $parameters = array_unique($parameters);
        return $parameters;
    }

}