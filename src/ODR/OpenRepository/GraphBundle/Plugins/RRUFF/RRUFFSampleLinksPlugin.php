<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Sample Links Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This plugin creates two "quick search" links based on the IMA Mineral this sample links to.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Services
use ODR\OpenRepository\GraphBundle\Plugins\ThemeElementPluginInterface;
// Symfony
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Routing\Router;


class RRUFFSampleLinksPlugin implements ThemeElementPluginInterface
{

    /**
     * @var SearchKeyService
     */
    private $search_key_service;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF Sample Links Plugin constructor
     *
     * @param SearchKeyService $search_key_service
     * @param Router $router
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        SearchKeyService $search_key_service,
        Router $router,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->search_key_service = $search_key_service;
        $this->router = $router;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin is only allowed to execute in display mode
            if ( $context === 'display' )
                return true;

            // Design mode doesn't call this, as it only demands placeholder HTML
        }

        return false;
    }


    /**
     * Executes the RRUFF Sample Links Plugin on the provided datarecord
     *
     * @param array $datarecord
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecord, $datatype, $render_plugin_instance, $theme_array, $rendering_options)
    {
        try {
            // ----------------------------------------
            // Shouldn't happen, but make sure this only executes in display mode
            if ( !isset($rendering_options['context']) || $rendering_options['context'] !== 'display' )
                return '';


            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // This plugin has no required fields or options, but does require a descendent that is
            //  using the "IMA Plugin"
            $ima_plugin_dt = null;
            $rpm = null;
            foreach ($datatype['descendants'] as $dt_id => $dt_wrapper) {
                if ( isset($dt_wrapper['datatype'][$dt_id]) ) {
                    // $dt won't exist if the user can't view the datatype
                    $dt = $dt_wrapper['datatype'][$dt_id];

                    foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.ima' ) {
                            // Found the datatype, save its renderPluginMap array and break out of both
                            //  loops
                            $ima_plugin_dt = $dt;
                            $rpm = $rpi['renderPluginMap'];
                            break 2;
                        }
                    }
                }
            }
            // ...if the datatype is using the "IMA Plugin", it'll have a renderPluginMap array
            if ( is_null($rpm) ) {
                if ( $is_datatype_admin )
                    // Only throw an error if the user is a datatype admin...
                    throw new \Exception('Unable to locate a descendent datatype using the "IMA Plugin"');
                else
                    // ...because if they're not, then the user can't do anything about it
                    return '';
            }

            // This plugin is interested in two of the fields from the IMA Plugin...
            $mineral_name_df_id = null;
            if ( isset($rpm['Mineral Name']) )
                $mineral_name_df_id = $rpm['Mineral Name']['id'];

            $tags_df_id = null;
            if ( isset($rpm['Tags']) )
                $tags_df_id = $rpm['Tags']['id'];

            // ...if either field is null, then this plugin can't work
            if ( is_null($mineral_name_df_id) || is_null($tags_df_id) ) {
                if ( $is_datatype_admin )
                    // Only throw an error if the user is a datatype admin...
                    throw new \Exception('Unable to locate the required fields from the "IMA Plugin" datatype for the "RRUFF Sample Links" plugin to work');
                else
                    // ...because if they're not, then the user can't do anything about it
                    return '';
            }


            // ----------------------------------------
            // Going to attempt to extract these values from a child record using the "IMA Plugin"
            $mineral_name_value = null;
            $structural_group_tag_data = null;

            $ima_plugin_dt_id = $ima_plugin_dt['id'];
            if ( !isset($datarecord['children'][$ima_plugin_dt_id]) ) {
                // If this RRUFF Sample doesn't link to an IMA Mineral, then this plugin can't do
                //  anything...but it's technically not an error
                return '';
            }
            else {
                // If this RRUFF Sample does link to an IMA Mineral, then there should only be one...
                foreach ($datarecord['children'][$ima_plugin_dt_id] as $child_dr_id => $child_dr) {
                    // Extract both the Mineral Name and the Structural Group value for this mineral
                    $mineral_name_value = self::findMineralNameValue($is_datatype_admin, $ima_plugin_dt, $child_dr, $mineral_name_df_id);
                    $structural_group_tag_data = self::findStructuralGroup($is_datatype_admin, $ima_plugin_dt, $child_dr, $tags_df_id);
                }
            }

            // If neither field has a value for some reason, then this plugin can't do anything
            //  ...but it's technically not an error
            if ( is_null($mineral_name_value) && is_null($structural_group_tag_data) )
                return '';


            // ----------------------------------------
            // Generate the search keys for the values that aren't blank
            $mineral_search_url = '';
            if ( !is_null($mineral_name_value) ) {
                $params = array(
                    'dt_id' => $datatype['id'],
                    $mineral_name_df_id => '"'.$mineral_name_value.'"',
                );
                $mineral_name_search_key = $this->search_key_service->encodeSearchKey($params);
                $mineral_search_url = $this->router->generate(
                    'odr_search_render',
                    array(
                        'search_theme_id' => 0,
                        'search_key' => $mineral_name_search_key
                    )
                );
            }

            $structural_group_search_url = '';
            $structural_group_value = '';
            if ( !is_null($structural_group_tag_data) ) {
                $structural_group_value = $structural_group_tag_data['value'];

                $params = array(
                    'dt_id' => $datatype['id'],
                    $tags_df_id => '+'.$structural_group_tag_data['id'],
                );
                $structural_group_search_key = $this->search_key_service->encodeSearchKey($params);
                $structural_group_search_url = $this->router->generate(
                    'odr_search_render',
                    array(
                        'search_theme_id' => 0,
                        'search_key' => $structural_group_search_key
                    )
                );
            }

            // TODO - RRUFF displayed how many records matched both of these quick searches
            // TODO - ...should this plugin do that too?  Have to prevent it from working on a search results page though...


            // ----------------------------------------
            // Otherwise, render and return a bit of HTML for the themeElement to display
            return $this->templating->render(
                'ODROpenRepositoryGraphBundle:RRUFF:RRUFFSampleLinks/rruff_sample_links_themeElement.html.twig',
                array(
                    'mineral_name_value' => $mineral_name_value,
                    'structural_group_value' => $structural_group_value,

                    'structural_group_search_url' => $structural_group_search_url,
                    'mineral_search_url' => $mineral_search_url,
                )
            );
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Extracts and returns the value for the given IMA Mineral's name.
     *
     * @param bool $is_datatype_admin
     * @param array $ima_dt
     * @param array $ima_dr
     * @param int $mineral_name_df_id
     *
     * @return string|null
     */
    private function findMineralNameValue($is_datatype_admin, $ima_dt, $ima_dr, $mineral_name_df_id)
    {
        // Might as well get the typeclass so the dataRecordField entry doesn't have to be brute-forced...
        $mineral_name_typeclass = lcfirst($ima_dt['dataFields'][$mineral_name_df_id]['dataFieldMeta']['fieldType']['typeClass']);

        // NOTE: the IMA Plugin requires this field to have a value, so it should always exist

        // Extract and return the value of the mineral name from the relevant datarecord
        if ( isset($ima_dr['dataRecordFields'][$mineral_name_df_id]) ) {
            $drf = $ima_dr['dataRecordFields'][$mineral_name_df_id];
            if ( isset($drf[$mineral_name_typeclass][0]['value']) )
                return $drf[$mineral_name_typeclass][0]['value'];
        }

        // Otherwise, no value set for the mineral name
        return null;
    }


    /**
     * Extracts and returns the value for the given IMA Mineral's structural group.
     *
     * @param bool $is_datatype_admin
     * @param array $ima_plugin_dt
     * @param array $child_dr
     * @param int $tags_df_id
     *
     * @throws \Exception
     * @return array|null
     */
    private function findStructuralGroup($is_datatype_admin, $ima_plugin_dt, $child_dr, $tags_df_id)
    {
        // Need this for errors
        $dt_name = $ima_plugin_dt['dataTypeMeta']['shortName'];

        // Verify that the given datarecord has at least one selected tag before attempting to
        //  determine if it's a "Structural Group" tag...
        if ( !isset($child_dr['dataRecordFields'][$tags_df_id]) || empty($child_dr['dataRecordFields'][$tags_df_id]['tagSelection']) )
            return null;

        // Otherwise, extract which tags are selected for later
        $selected_tags = $child_dr['dataRecordFields'][$tags_df_id]['tagSelection'];


        // ----------------------------------------
        // The array of selected tags doesn't contain any information about the parents of those
        //  selected tags...which means this function has to dig through the datatype array in order
        //  to locate which tags have "Structural Groups" as a parent
        $tags = $ima_plugin_dt['dataFields'][$tags_df_id]['tags'];

        // The top-level tag should be named "Mineral Groups"....
        $mineral_groups_tag = null;
        foreach ($tags as $tag_id => $tag) {
            if ( strtolower($tag['tagName']) === 'mineral groups') {
                $mineral_groups_tag = $tag;
                break;
            }
        }
        if ( is_null($mineral_groups_tag) ) {
            if ( $is_datatype_admin )
                // Only throw an error if the user is a datatype admin...
                throw new \Exception('Unable to find the required "Mineral Groups" tag inside the "'.$dt_name.'" datatype');
            else
                // ...because if they're not, then the user can't do anything about it
                return null;
        }


        // ...and the second level should be named "Structural groups"
        $structural_groups_tag = null;
        foreach ($mineral_groups_tag['children'] as $tag_id => $tag) {
            if ( strtolower($tag['tagName']) === 'structural groups') {
                $structural_groups_tag = $tag;
                break;
            }
        }
        if ( is_null($structural_groups_tag) ) {
            if ( $is_datatype_admin )
                // Only throw an error if the user is a datatype admin...
                throw new \Exception('Unable to find the required "Structural Groups" tag inside the "'.$dt_name.'" datatype');
            else
                // ...because if they're not, then the user can't do anything about it
                return null;
        }


        // Now that the valid structural groups have been located...
        $all_structual_groups = $structural_groups_tag['children'];

        // ...the datarecord array can now be used to find which tags are actually selected and
        //  have the "Structural Groups" tag as their parent
        foreach ($selected_tags as $tag_id => $tag) {
            if ( $tag['selected'] === 1 && isset($all_structual_groups[$tag_id]) )
                return array('id' => $tag_id, 'value' => $all_structual_groups[$tag_id]['tagName']);
        }

        // If this point is reached, the the mineral does not have a "Structural Group" tag selected
        return null;
    }


    /**
     * Returns placeholder HTML for a themeElement RenderPlugin for design mode.
     *
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function getPlaceholderHTML($datatype, $render_plugin_instance, $theme_array, $rendering_options)
    {
        // Render the placeholder html
        return $this->templating->render(
            'ODROpenRepositoryGraphBundle:RRUFF:RRUFFSampleLinks/rruff_sample_links_placeholder.html.twig'
        );
    }
}
