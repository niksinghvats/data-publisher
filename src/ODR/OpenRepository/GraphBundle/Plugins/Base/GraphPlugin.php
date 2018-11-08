<?php 

/**
 * Open Data Repository Data Publisher
 * Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The graph plugin plots a line graph out of data files uploaded to a File DataField, and labels
 * them using a "legend" field selected when the graph plugin is created...
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Interfaces
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\GraphPluginInterface;
// ODR
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
// Other
use Ramsey\Uuid\Uuid;


class GraphPlugin implements DatatypePluginInterface, GraphPluginInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var CryptoService
     */
    private $crypto_service;

    /**
     * @var string
     */
    private $odr_web_directory;


    /**
     * GraphPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param CryptoService $crypto_service
     * @param $odr_web_directory
     */
    public function __construct(EngineInterface $templating, CryptoService $crypto_service, $odr_web_directory)
    {
        $this->templating = $templating;
        $this->crypto_service = $crypto_service;
        $this->odr_web_directory = $odr_web_directory;
    }


    /**
     * Executes the Graph Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options)
    {

        try {
            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $max_option_date = 0;
            $options = array();
            foreach ($render_plugin_options as $option) {
                if ( $option['active'] == 1 ) {
                    $option_date = new \DateTime($option['updated']->date);
                    $us = $option_date->format('u');
                    $epoch = strtotime($option['updated']->date) * 1000000;
                    $epoch = $epoch + $us;
                    if ($epoch > $max_option_date)
                        $max_option_date = $epoch;

                    $options[$option['optionName']] = $option['optionValue'];
                }
            }

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];
                $df_id = $rpm['dataField']['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                    $df = $datatype['dataFields'][$df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df);
            }

            // Need to sort by the datarecord's sort value if possible
            $datarecord_sortvalues = array();
            $sort_typeclass = '';

            $legend_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                // Store sort values for later...
                $datarecord_sortvalues[$dr_id] = $dr['sortField_value'];
                $sort_typeclass = $dr['sortField_typeclass'];

                // Locate the value for the Pivot Field if possible
                $legend_datafield_id = $datafield_mapping['pivot_field']['datafield']['id'];
                $legend_datafield_typeclass = $datafield_mapping['pivot_field']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                $entity = array();
                if(isset($dr['dataRecordFields'][$legend_datafield_id])) {

                    $drf = $dr['dataRecordFields'][$legend_datafield_id];
                    switch ($legend_datafield_typeclass) {
                        case 'IntegerValue':
                            if (isset($drf['integerValue'])) {
                                $entity = $drf['integerValue'];
                            }
                            break;
                        case 'ShortVarchar':
                            if (isset($drf['shortVarchar'])) {
                                $entity = $drf['shortVarchar'];
                            }
                            break;
                        case 'MediumVarchar':
                            if (isset($drf['mediumVarchar'])) {
                                $entity = $drf['mediumVarchar'];
                            }
                            break;
                        case 'LongVarchar':
                            if (isset($drf['longVarchar'])) {
                                $entity = $drf['longVarchar'];
                            }
                            break;

                        default:
                            throw new \Exception('Invalid Fieldtype for legend_field');
                            break;
                    }

                    $legend_values[$dr_id] = $entity[0]['value'];
                }
                else {
                    // Use Datafield ID as Pivot Value
                    $legend_values[$dr_id] = $legend_datafield_id;
                }
            }

            // Sort datarecords by their sortvalue
            $flag = SORT_NATURAL;
            if ($sort_typeclass == 'IntegerValue' ||
                $sort_typeclass == 'DecimalValue' ||
                $sort_typeclass == ''   // if empty string, sort values will be datarecord ids
            ) {
                $flag = SORT_NUMERIC;
            }

            asort($datarecord_sortvalues, $flag);
            $datarecord_sortvalues = array_flip( array_keys($datarecord_sortvalues) );


            // ----------------------------------------
            // Initialize arrays
            $odr_chart_ids = array();
            $odr_chart_file_ids = array();
            $odr_chart_files = array();
            $odr_chart_output_files = array();
            // We must create all file names if not using rollups

            $datatype_folder = '';

            // Or create rollup name for rollup chart
            foreach ($datarecords as $dr_id => $dr) {
                $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
                if ( isset($dr['dataRecordFields'][$graph_datafield_id]) ) {
                    foreach ($dr['dataRecordFields'][$graph_datafield_id]['file'] as $file_num => $file) {

                        if ( $file_num > 0 ) {
                            $df_name = $datafield_mapping['graph_file']['datafield']['dataFieldMeta']['fieldName'];
                            $file_count = count( $dr['dataRecordFields'][$graph_datafield_id]['file'] );

                            throw new \Exception('The Graph Plugin can only handle a single uploaded file per datafield, but the Datafield "'.$df_name.'" has '.$file_count.' uploaded files.');
                        }

                        if ($datatype_folder === '')
                            $datatype_folder = 'datatype_'.$dr['dataType']['id'].'/';

                        // File ID list is used only by rollup
                        $odr_chart_file_ids[] = $file['id'];

                        // This is the master data used to graph
                        $odr_chart_files[$dr_id] = $file;

                        // We need to generate a unique chart id for each chart
                        $odr_chart_id = "plotly_" . Uuid::uuid4()->toString();
                        $odr_chart_id = str_replace("-","_", $odr_chart_id);
                        $odr_chart_ids[$dr_id] = $odr_chart_id;


                        // This filename must remain predictable
                        // TODO - Long term the UUID should be saved to database
                        // whenever a new chart is created.  This UUID should be unique
                        // to each file version to prevent scraping of data.
                        $filename = 'Chart__' . $file['id'] . '_' . $max_option_date . '.svg';
                        $odr_chart_output_files[$dr_id] = '/uploads/files/graphs/'.$datatype_folder.$filename;
                    }
                }
            }


            // If $datatype_folder is blank at this point, it's most likely because the user can
            //  view the datarecord/datafield, but not the file...so the graph can't be displayed
            $display_graph = true;
            if ($datatype_folder === '')
                $display_graph = false;

            // Only create a directory for the graph file if the graph is actually being displayed...
            if ( $display_graph && !file_exists($this->odr_web_directory.'/uploads/files/graphs/'.$datatype_folder) )
                mkdir($this->odr_web_directory.'/uploads/files/graphs/'.$datatype_folder);


            // Rollup related calculations
            $file_id_list = implode('_', $odr_chart_file_ids);

            // Generate the rollup chart ID for the page chart object
            $odr_chart_id = "Chart_" . Uuid::uuid4()->toString();
            $odr_chart_id = str_replace("-","_", $odr_chart_id);
            $filename = 'Chart__' . $file_id_list. '_' . $max_option_date . '.svg';

            // Add a rollup chart
            $odr_chart_ids['rollup'] = $odr_chart_id;
            $odr_chart_output_files['rollup'] = '/uploads/files/graphs/'.$datatype_folder.$filename;


            // ----------------------------------------
            // Should only be one element in $theme_array...
            $theme = null;
            foreach ($theme_array as $t_id => $t)
                $theme = $t;

            // Pulled up here so build graph can access the data
            $page_data = array(
                'datatype_array' => array($datatype['id'] => $datatype),
                'datarecord_array' => $datarecords,
                'theme_array' => $theme_array,

                'target_datatype_id' => $datatype['id'],
                'target_theme_id' => $theme['id'],

                'is_top_level' => $rendering_options['is_top_level'],
                'is_link' => $rendering_options['is_link'],
                'display_type' => $rendering_options['display_type'],
                'display_graph' => $display_graph,

                // Options for graph display
                'render_plugin' => $render_plugin,
                'plugin_options' => $options,

                // All of these are indexed by datarecord id
                'odr_chart_ids' => $odr_chart_ids,
                'odr_chart_legend' => $legend_values,
                'odr_chart_file_ids' => $odr_chart_file_ids,
                'odr_chart_files' => $odr_chart_files,
                'odr_chart_output_files' => $odr_chart_output_files,

                'datarecord_sortvalues' => $datarecord_sortvalues,
            );

            if ( isset($rendering_options['build_graph']) ) {
                // Determine file name
                $graph_filename = "";
                if ( isset($options['use_rollup']) && $options['use_rollup'] == "yes" ) {
                    $graph_filename = $odr_chart_output_files['rollup'];
                }
                else {
                    // Determine target datarecord
                    if ( isset($rendering_options['datarecord_id'])
                        && preg_match("/^\d+$/", trim($rendering_options['datarecord_id']))
                    ) {
                        $graph_filename = $odr_chart_output_files[$rendering_options['datarecord_id']];
                    }
                    else {
                        throw new \Exception('Target data record id not set.');
                    }
                }


                // We need to know if this is a rollup or direct record request here...
                if ( file_exists($this->odr_web_directory.$graph_filename) ) {
                    /* Pre-rendered graph file exists, do nothing */
                    return $graph_filename;
                }
                else {
                    // In this case, we should be building a single graph (either rollup or individual datarecord)

                    $files_to_delete = array();
                    if ( isset($options['use_rollup']) && $options['use_rollup'] == "yes" ) {
                        // For each of the files that will be used in the graph...
                        foreach ($odr_chart_files as $dr_id => $file) {
                            // ...ensure that it exists
                            $filepath = $this->odr_web_directory.'/'.$file['localFileName'];
                            if ( !file_exists($filepath) ) {
                                // File does not exist, decrypt it
                                $file_path = $this->crypto_service->decryptFile($file['id']);

                                // If file is not public, make sure it gets deleted later
                                $public_date = $file['fileMeta']['publicDate'];
                                $now = new \DateTime();
                                if ($now < $public_date)
                                    array_push($files_to_delete, $file_path);
                            }
                        }

                        // Set the chart id
                        $page_data['odr_chart_id'] = $odr_chart_ids['rollup'];
                    }
                    else {
                        // Only a single file will be needed.  Check if it needs to be decrypted.
                        $dr_id = $rendering_options['datarecord_id'];
                        $file = $odr_chart_files[$dr_id];

                        $filepath = $this->odr_web_directory.'/'.$file['localFileName'];
                        if ( !file_exists($filepath) ) {
                            // File does not exist, decrypt it
                            $file_path = $this->crypto_service->decryptFile($file['id']);

                            // If file is not public, make sure it gets deleted later
                            $public_date = $file['fileMeta']['publicDate'];
                            $now = new \DateTime();
                            if ($now < $public_date)
                                array_push($files_to_delete, $file_path);
                        }

                        // Set the chart id
                        $page_data['odr_chart_id'] = $odr_chart_ids[$dr_id];

                        $page_data['odr_chart_file_ids'] = array($file['id']);
                        $page_data['odr_chart_files'] = array($dr_id => $file);

                        $filename = 'Chart__'.$file['id'].'_'.$max_option_date.'.svg';
                    }

                    // Pre-rendered graph file does not exist...need to create it
                    $output_filename = self::buildGraph($page_data, $filename);

                    // Delete previously encrypted non-public files
                    foreach ($files_to_delete as $file_path)
                        unlink($file_path);

                    // File has been created.  Now can return it.
                    return $output_filename;
                }
            }
            else {
                // Render the graph html
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Graph/graph_wrapper.html.twig', $page_data
                );
            }

            if (!isset($rendering_options['build_graph'])) {
                return $output;
            }
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Builds the static graphs for the server.
     *
     * @param array $page_data A Map holding all the data that is needed for creating the graph
     *                          html, and for the phantomjs js server to render it.
     * @param string $filename The name that the svg file should have.
     *
     * @throws \Exception
     *
     * @return string
     */
    private function buildGraph($page_data, $filename)
    {
        // Prepare the file_id list
        $file_id_list = implode('_', $page_data['odr_chart_file_ids']);

        // Path to writeable files in web folder
        $files_path = $this->odr_web_directory.'/uploads/files/';
        $fs = new \Symfony\Component\Filesystem\Filesystem();

        // The HTML file that generates the svg graph that will be saved to the server by Phantomjs.
        //TODO Make paths relative
        $output1 = $this->templating->render(
            'ODROpenRepositoryGraphBundle:Base:Graph/graph_builder.html.twig', $page_data
        );
        $fs->dumpFile($files_path . "Chart__" . $file_id_list . '.html', $output1);

        $datatype_folder = 'datatype_'.$page_data['target_datatype_id'].'/';

        // Temporary output file masked by UUIDv4 (random)
        // TODO - Create cleaner to remove masked_files from /tmp
        $output_tmp_svg = "/tmp/graph_" . Uuid::uuid4()->toString();
        $output_svg = $files_path.'graphs/'.$datatype_folder.$filename;

        // JSON data to be passed to the phantom js server
        $json_data = array(
            "data" => array(
                'URL' => $files_path . "Chart__" . $file_id_list . '.html',
                'selector' => $page_data['odr_chart_id'],
                'output' => $output_tmp_svg
            )
        );

        $data_string = json_encode($json_data);

        //Curl request to the PhantomJS server
        $ch = curl_init('localhost:9494');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));

        $response = curl_exec($ch);
        curl_close($ch);

        // Parse output to fix CamelCase in SVG element
        if ( file_exists($output_tmp_svg) ) {
            $created_file = file_get_contents($output_tmp_svg);
            $fixed_file = str_replace('viewbox', 'viewBox', $created_file);
            $fixed_file = str_replace('preserveaspectratio', 'preserveAspectRatio', $fixed_file);
            file_put_contents($output_svg, $fixed_file);

            // Remove the HTML file
            unlink($files_path . "Chart__" . $file_id_list . '.html');
            return '/uploads/files/graphs/'.$datatype_folder.$filename;
        }
        else {
            if ( strlen($output_svg) > 40 ) {
                $output_svg = "..." . substr($output_svg,(strlen($output_svg) - 40), strlen($output_svg));
            }

            throw new \Exception('The file "'. $output_svg .'" does not exist');
        }
    }


    /**
     * Called when a user removes a specific instance of this render plugin
     *
     * @param RenderPluginInstance $render_plugin_instance
     */
    public function onRemoval($render_plugin_instance)
    {
        // This plugin doesn't need to do anything here
        // TODO - make this plugin delete cached graphs on removal?
        return;
    }


    /**
     * Called when a user changes a mapped field or an option for this render plugin
     * TODO - pass in which field mappings and/or plugin options got changed?
     *
     * @param RenderPluginInstance $render_plugin_instance
     */
    public function onSettingsChange($render_plugin_instance)
    {
        // This plugin doesn't need to do anything here
        // TODO - make this plugin delete cached graphs on settings change?  right now, changing an option ends up changing the filename for the cached graph...
        return;
    }


    /**
     * Called when a file used by this render plugin is replaced or deleted.
     *
     * This might change in the future, but at the moment...the only relevant render plugin uses
     * the file id as part of the cache entry filename.
     *
     * @param DataFields $datafield
     * @param int $file_id
     */
    public function onFileChange($datafield, $file_id)
    {
        // Filenames of cached graphs have the ids of all the files that were read to create them
        $filename_fragment = '_'.$file_id.'_';

        // Graphs are organized into subdirectories by datatype id
        $datatype_id = $datafield->getDataType()->getId();
        $graph_filepath = $this->odr_web_directory.'/uploads/files/graphs/datatype_'.$datatype_id.'/';
        if ( file_exists($graph_filepath) ) {
            $files = scandir($graph_filepath);
            foreach ($files as $filename) {
                // TODO - assumes linux?
                if ($filename === '.' || $filename === '..')
                    continue;

                // If this cached graph used this file, unlink it to force a rebuild later on
                if ( strpos($filename, $filename_fragment) !== false )
                    unlink($graph_filepath.'/'.$filename);
            }
        }
    }
}
