<?php 

/**
 * Open Data Repository Data Publisher
 * RRUFF References Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RRUFF References plugin works like the standard references plugin, but also has an autogenerated
 * external ID.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
use ODR\AdminBundle\Entity\DataFields;
// Events
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class RRUFFReferencesPlugin implements DatatypePluginInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF References constructor
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param EntityCreationService $entity_creation_service
     * @param LockService $lock_service
     * @param SearchCacheService $search_cache_service
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        EntityCreationService $entity_creation_service,
        LockService $lock_service,
        SearchCacheService $search_cache_service,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dbi_service = $database_info_service;
        $this->ec_service = $entity_creation_service;
        $this->lock_service = $lock_service;
        $this->search_cache_service = $search_cache_service;
        $this->token_manager = $token_manager;
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
        // TODO - make changes so the plugin can continue to run in Edit mode?
        if ( isset($rendering_options['context']) ) {
            if ($rendering_options['context'] === 'display'
                || $rendering_options['context'] === 'fake_edit'
            ) {
                // Needs to be executed in both fake_edit (for autogeneration) and display modes
                return true;
            }

            // Also need a "text" mode
            if ( $rendering_options['context'] === 'text' )
                return true;
        }

        // Otherwise, don't execute the plugin
        return false;
    }


    /**
     * Executes the RRUFF References Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if ( !isset($rendering_options['context']) )
                return '';


            // ----------------------------------------
            // Going to need these...
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;


            // ----------------------------------------
            // Output depends on which context the plugin is being executed from
            $output = '';
            if ( $rendering_options['context'] === 'display' || $rendering_options['context'] === 'text' ) {

                // Want to locate the values for each of the mapped datafields
                $datafield_mapping = array();
                foreach ($fields as $rpf_name => $rpf_df) {
                    // Need to find the real datafield entry in the primary datatype array
                    $rpf_df_id = $rpf_df['id'];

                    $df = null;
                    if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                        $df = $datatype['dataFields'][$rpf_df_id];

                    if ($df == null)
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                    $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                    // Grab the fieldname specified in the plugin's config file to use as an array key
                    $key = strtolower(str_replace(' ', '_', $rpf_name));

                    // The datafield may have a render plugin that should be executed...
                    if ( !empty($df['renderPluginInstances']) ) {
                        foreach ($df['renderPluginInstances'] as $rpi_num => $rpi) {
                            if ( $rpi['renderPlugin']['render'] === true ) {
                                // ...if it does, then create an array entry for it
                                $datafield_mapping[$key] = array(
                                    'datafield' => $df,
                                    'render_plugin_instance' => $rpi
                                );
                            }
                        }
                    }

                    // If it does have a render plugin, then don't bother looking in the datarecord array
                    //  for the value
                    if ( isset($datafield_mapping[$key]) )
                        continue;


                    // Otherwise, look for the value in the datarecord array
                    if ( !isset($datarecord['dataRecordFields'][$rpf_df_id]) ) {
                        // As far as the reference plugin is concerned, empty strings are acceptable
                        //  values when datarecordfield entries don't exist
                        $datafield_mapping[$key] = '';
                    }
                    else if ($typeclass === 'File') {
                        $datafield_mapping[$key] = array(
                            'datarecordfield' => $datarecord['dataRecordFields'][$rpf_df_id]
                        );
                    }
                    else {
                        // Don't need to execute a render plugin on this datafield's value...extract it
                        //  directly from the datarecord array
                        // $drf is guaranteed to exist at this point
                        $drf = $datarecord['dataRecordFields'][$rpf_df_id];
                        $value = '';

                        switch ($typeclass) {
                            case 'IntegerValue':
                                $value = $drf['integerValue'][0]['value'];
                                break;
                            case 'DecimalValue':
                                $value = $drf['decimalValue'][0]['value'];
                                break;
                            case 'ShortVarchar':
                                $value = $drf['shortVarchar'][0]['value'];
                                break;
                            case 'MediumVarchar':
                                $value = $drf['mediumVarchar'][0]['value'];
                                break;
                            case 'LongVarchar':
                                $value = $drf['longVarchar'][0]['value'];
                                break;
                            case 'LongText':
                                $value = $drf['longText'][0]['value'];
                                break;
                            case 'DateTimeValue':
                                $value = $drf['dateTimeValue'][0]['value']->format('Y-m-d');
                                if ($value == '9999-12-31')
                                    $value = '';
                                $datafield_mapping[$key] = $value;
                                break;

                            default:
                                throw new \Exception('Invalid Fieldtype');
                                break;
                        }

                        $datafield_mapping[$key] = trim($value);
                    }
                }


                // Going to render the reference differently if it's top-level...
                $is_top_level = $rendering_options['is_top_level'];

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFReferences/rruffreferences_display.html.twig',
                    array(
                        'datarecord' => $datarecord,
                        'mapping' => $datafield_mapping,

                        'is_top_level' => $is_top_level,
                        'original_context' => $rendering_options['context'],
                    )
                );
            }
            else if ( $rendering_options['context'] === 'fake_edit') {
                // Retrieve mapping between datafields and render plugin fields
                $autogenerate_df_id = null;
                $plugin_fields = array();
                foreach ($fields as $rpf_name => $rpf_df) {
                    // Need to find the real datafield entry in the primary datatype array
                    $rpf_df_id = $rpf_df['id'];

                    $df = null;
                    if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                        $df = $datatype['dataFields'][$rpf_df_id];

                    if ($df == null)
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                    // Need to tweak display parameters for several of the fields...
                    $plugin_fields[$rpf_df_id] = $rpf_df;
                    $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;

                    // Need to generate a special token for the reference_id field
                    if ( $rpf_name === 'Reference ID' )
                        $autogenerate_df_id = $rpf_df_id;
                }

                // Need to provide a special token so the "Reference ID" field won't get ignored
                //  by FakeEdit due to preventing user edits...
                $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$autogenerate_df_id.'_autogenerated';
                $token = $this->token_manager->getToken($token_id)->getValue();
                $special_tokens[$autogenerate_df_id] = $token;

                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFReferences/rruffreferences_fakeedit_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => array($datarecord['id'] => $datarecord),
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,
                        'special_tokens' => $special_tokens,

                        'plugin_fields' => $plugin_fields,
                    )
                );
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Handles when a datarecord is created.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        // Pull some required data from the event
        $user = $event->getUser();
        $datarecord = $event->getDatarecord();
        $datatype = $datarecord->getDataType();

        // Need to locate the "mineral_id" field for this render plugin...
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:RenderPlugin rp
            JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
            JOIN ODRAdminBundle:RenderPluginMap rpm WITH rpm.renderPluginInstance = rpi
            JOIN ODRAdminBundle:DataFields df WITH rpm.dataField = df
            JOIN ODRAdminBundle:RenderPluginFields rpf WITH rpm.renderPluginFields = rpf
            WHERE rp.pluginClassName = :plugin_classname AND rpi.dataType = :datatype
            AND rpf.fieldName = :field_name
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
            AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classname' => 'odr_plugins.rruff.rruff_references',
                'datatype' => $datatype->getId(),
                'field_name' => 'Reference ID'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 )
            throw new ODRException('Unable to find the "Reference ID" field for the "RRUFF References" RenderPlugin, attached to Datatype '.$datatype->getId());

        // Will only be one result, at this point
        $datafield = $results[0];
        /** @var DataFields $datafield */


        // ----------------------------------------
        // Need to acquire a lock to ensure that there are no duplicate values
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_autogenerate_id'.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Now that a lock is acquired, need to find the "most recent" value for the field that is
        //  getting incremented...
        $old_value = self::findCurrentValue($datafield->getId());

        // Since the "most recent" mineral id is already an integer, just add 1 to it
        $new_value = $old_value + 1;

        // Create a new storage entity with the new value
        $this->ec_service->createStorageEntity($user, $datarecord, $datafield, $new_value, false);    // guaranteed to not need a PostUpdate event

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Not going to mark the datarecord as updated, but still need to do some other cache
        //  maintenance because a datafield value got changed...

        // If the datafield that got changed was the datatype's sort datafield, delete the cached datarecord order
        if ( $datatype->getSortField() != null && $datatype->getSortField()->getId() == $datafield->getId() )
            $this->dbi_service->resetDatatypeSortOrder($datatype->getId());

        // Delete any cached search results involving this datafield
        $this->search_cache_service->onDatafieldModify($datafield);
    }


    /**
     * For this database, the reference_id needs to be autogenerated.
     *
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements.
     *
     * @param int $datafield_id
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function findCurrentValue($datafield_id)
    {
        // Going to use native SQL...DQL can't use limit without using querybuilder...
        // NOTE - the query intentionally includes deleted entries, since we never want to reuse
        //  reference_ids even if the associated mineral got deleted for some reason
        $query =
           'SELECT e.value
            FROM odr_integer_value e
            WHERE e.data_field_id = :datafield
            ORDER BY e.value DESC
            LIMIT 0,1';
        $params = array(
            'datafield' => $datafield_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one value in the result...
        $current_value = null;
        foreach ($results as $result)
            $current_value = intval( $result['value'] );

        // ...but if there's not for some reason, return zero as the "current".  onDatarecordCreate()
        //  will increment it so that the value one is what will actually get saved.
        // NOTE - this shouldn't happen for the existing references
        if ( is_null($current_value) )
            $current_value = 0;

        return $current_value;
    }
}
