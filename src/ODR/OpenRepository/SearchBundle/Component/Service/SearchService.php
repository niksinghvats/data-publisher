<?php

/**
 * Open Data Repository Data Publisher
 * Search Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Coordinates searches against ODR, pulling search results from various cache entries if possible,
 *  and calling the appropriate SQL queries in SearchQueryService when not.
 *
 * IMPORTANT: The cache entries and the values returned from these functions ARE NOT filtered based
 * on user permissions.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var SearchQueryService
     */
    private $search_query_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchService constructor.
     *
     * @param EntityManager $entityManager
     * @param CacheService $cacheService
     * @param DatatypeInfoService $datatypeInfoService
     * @param SearchQueryService $searchQueryService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        CacheService $cacheService,
        DatatypeInfoService $datatypeInfoService,
        SearchQueryService $searchQueryService,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->cache_service = $cacheService;
        $this->dti_service = $datatypeInfoService;
        $this->search_query_service = $searchQueryService;
        $this->logger = $logger;
    }


    /**
     * Searches the specified radio option datafield using the selections array, and returns an
     * array of all datarecord ids that match the criteria.
     *
     * @param DataFields $datafield
     * @param array $selections An array with radio option ids for keys, and 0 or 1 for values
     * @param bool $merge_using_OR  If false, merge using AND instead
     *
     * @return array
     */
    public function searchRadioDatafield($datafield, $selections, $merge_using_OR = true)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'Radio' )
            throw new ODRBadRequestException('searchRadioDatafield() called with '.$typeclass.' datafield', 0x5e0dc6db);

        // Also don't continue if no selection specified
        if ( count($selections) == 0 )
            throw new ODRBadRequestException('searchRadioDatafield() called with empty selections array', 0x5e0dc6db);


        // ----------------------------------------
        // Otherwise, probably going to need to run searches again...
        $end_result = null;
        $datarecord_list = null;

        foreach ($selections as $radio_option_id => $value) {
            // Attempt to find the cached result for this radio option...
            $result = $this->cache_service->get('cached_search_ro_'.$radio_option_id);
            if ( !$result )
                $result = array();

            // If it doesn't exist...
            if ( !isset($result[$value]) ) {
                // ...then ensure we have the list of datarecords for this radio option's datatype
                if ( is_null($datarecord_list) ) {
                    $datarecord_list = self::getCachedSearchDatarecordList($datafield->getDataType()->getId());

                    // Probably not strictly necessary, but keep parent datarecord ids out of the
                    //  search results for this
                    foreach ($datarecord_list as $dr_id => $parent_id)
                        $datarecord_list[$dr_id] = 1;
                }

                // ...run the search again
                $result = $this->search_query_service->searchRadioDatafield(
                    $datarecord_list,
                    $radio_option_id
                );

                // ...and store it in the cache
                $this->cache_service->set('cached_search_ro_'.$radio_option_id, $result);
            }


            // TODO - Eventually going to need something more refined than just a single merge_type flag
            if ($merge_using_OR) {
                // If first run, then start from empty array due to using isset() below
                if ( is_null($end_result) )
                    $end_result = array();

                // array_merge() is slower than using isset() and array_flip() later
                foreach ($result[$value] as $dr_id => $num)
                    $end_result[$dr_id] = 1;
            }
            else {
                // If first run, then use the first set of results to start off with
                if ( is_null($end_result) ) {
                    $end_result = $result[$value];
                }
                else {
                    // Otherwise, only save the datarecord ids that are in both arrays
                    $end_result = array_intersect_key($end_result, $result[$value]);
                }

                // If nothing in the array, then no results are possible
                if ( count($end_result) == 0 )
                    break;
            }
        }

        // ...then return the search result
        $result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $end_result
        );

        return $result;
    }


    /**
     * Searches the specified radio option template datafield using the selections array, and
     * returns an array of all datarecord ids that match the criteria.
     *
     * @param DataFields $template_datafield
     * @param array $selections An array with radio option ids for keys, and 0 or 1 for values
     * @param bool $merge_using_OR  If false, merge using AND instead
     *
     * @return array
     */
    public function searchRadioTemplateDatafield($template_datafield, $selections, $merge_using_OR = true)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $template_datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'Radio' )
            throw new ODRBadRequestException('searchRadioTemplateDatafield() called with '.$typeclass.' datafield', 0x5e0dc6db);
        if ( !$template_datafield->getIsMasterField() )
            throw new ODRBadRequestException('searchRadioTemplateDatafield() called on a non-master datafield', 0x5e0dc6db);

        // Also don't continue if no selection specified
        if ( count($selections) == 0 )
            throw new ODRBadRequestException('searchRadioTemplateDatafield() called with empty selections array', 0x5e0dc6db);


        // ----------------------------------------
        // Otherwise, probably going to need to run searches again...
        $end_result = null;
        $datarecord_list = null;

        foreach ($selections as $radio_option_uuid => $value) {
            // Attempt to find the cached result for this radio option...
            $result = $this->cache_service->get('cached_search_template_ro_'.$radio_option_uuid);
            if ( !$result )
                $result = array();

            // If it doesn't exist...
            if ( !isset($result[$value]) ) {
                // ...then ensure we have the list of datarecords for this radio option's datatype
                if ( is_null($datarecord_list) )
                    $datarecord_list = self::getCachedTemplateDatarecordList($template_datafield->getDataType()->getUniqueId());

                // ...run the search again
                $result = $this->search_query_service->searchRadioTemplateDatafield(
                    $datarecord_list,
                    $radio_option_uuid
                );

                // NOTE - $result won't contain results from datatypes that aren't up to date
                // TODO - should they be automatically marked as "unselected"?

                // ...and store it in the cache
                $this->cache_service->set('cached_search_template_ro_'.$radio_option_uuid, $result);
            }


            // TODO - Eventually going to need something more refined than just a single merge_type flag
            if ($merge_using_OR) {
                // If first run, then start from empty array due to using isset() below
                if ( is_null($end_result) )
                    $end_result = array();

                $end_result = self::templateResultsUnion($end_result, $result[$value]);
            }
            else {
                // If first run, then use the first set of results to start off with
                if ( is_null($end_result) ) {
                    $end_result = $result[$value];
                }
                else {
                    // Otherwise, only save the datarecord ids that are in both arrays
                    $end_result = self::templateResultsIntersect($end_result, $result[$value]);
                }

                // If nothing in the array, then no results are possible
                if ( count($end_result) == 0 )
                    break;
            }
        }

        // ...then return the search result
        return $end_result;
    }


    /**
     * A function to union lists of datarecord ids that are several layers deep is required when
     * searching across templates for datarecords where at least one of the given radio options
     * are selected.
     *
     * @param array $first_array
     * @param array $second_array
     *
     * @return array
     */
    private function templateResultsUnion($first_array, $second_array)
    {
        foreach ($second_array as $dt_id => $df_list) {
            if ( !isset($first_array[$dt_id]) )
                $first_array[$dt_id] = array();

            foreach ($df_list as $df_id => $dr_list) {
                if ( !isset($first_array[$dt_id][$df_id]) )
                    $first_array[$dt_id][$df_id] = array();

                foreach ($dr_list as $dr_id => $num)
                    $first_array[$dt_id][$df_id][$dr_id] = 1;
            }
        }

        return $first_array;
    }


    /**
     * Computing the intersection of lists of datarecord ids that are several layers deep is
     * required when searching across templates for datarecords where all of the given radio options
     * are selected.
     *
     * @param array $first_array
     * @param array $second_array
     *
     * @return array
     */
    private function templateResultsIntersect($first_array, $second_array)
    {
        foreach ($first_array as $dt_id => $df_list) {
            if ( !isset($second_array[$dt_id]) ) {
                unset( $first_array[$dt_id] );
            }
            else {
                foreach ($df_list as $df_id => $dr_list) {
                    if ( !isset($second_array[$dt_id][$df_id]) ) {
                        unset( $first_array[$dt_id][$df_id] );
                    }
                    else {
                        foreach ($dr_list as $dr_id => $num) {
                            if ( !isset($second_array[$dt_id][$df_id][$dr_id]) )
                                unset( $first_array[$dt_id][$df_id][$dr_id] );
                        }
                    }
                }
            }
        }

        return $first_array;
    }


    /**
     * Searches for specified datafield for any selected radio options matching the given criteria.
     *
     * @param DataFields $datafield
     * @param string $value
     *
     * @return array
     */
    public function searchForSelectedRadioOptions($datafield, $value)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'Radio' )
            throw new ODRBadRequestException('searchForSelectedRadioOptions() called with '.$typeclass.' datafield', 0x4f2a33f4);


        // ----------------------------------------
        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$value]) )
            return $cached_searches[$value];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchForSelectedRadioOptions(
            $datafield->getId(),
            $value
        );

        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $result
        );

        // ...then recache the search result
        $cached_searches[$value] = $end_result;
        $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Searches the specified radio option template datafield for any selected radio options
     * matching the given criteria, and returns an array of all datarecord ids that match the
     * criteria.
     *
     * @param DataFields $template_datafield
     * @param string $value
     *
     * @return array
     */
    public function searchForSelectedRadioTemplateOptions($template_datafield, $value)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $template_datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'Radio' )
            throw new ODRBadRequestException('searchForSelectedRadioTemplateOptions() called with '.$typeclass.' datafield', 0x4f2a33f4);
        if ( !$template_datafield->getIsMasterField() )
            throw new ODRBadRequestException('searchForSelectedRadioTemplateOptions() called with non-master datafield', 0x4f2a33f4);


        // ----------------------------------------
        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_template_df_'.$template_datafield->getFieldUuid());
        if ( !$cached_searches )
            $cached_searches = array();

        // TODO - cross-template search is currently locked to "any" radio option...need to allow searches on radio option names
        $value = 'any';
        if ( isset($cached_searches[$value]) )
            return $cached_searches[$value];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchForSelectedTemplateRadioOptions(
            $template_datafield->getDataType()->getUniqueId(),
            $template_datafield->getFieldUuid(),
            $value
        );


        // ...then recache the search result
        $cached_searches[$value] = $result;
        $this->cache_service->set('cached_search_template_df_'.$template_datafield->getFieldUuid(), $cached_searches);

        // ...then return it
        return $result;
    }


    /**
     * Searches the given file or image datafield by either the given filename or whether it has
     * any files or not, and returns an array of datarecord ids that match the given criteria.
     *
     * @param DataFields $datafield
     * @param string|null $filename
     * @param bool|null $has_files
     *
     * @return array
     */
    public function searchFileOrImageDatafield($datafield, $filename = null, $has_files = null)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $allowed_typeclasses = array(
            'File',
            'Image',
        );
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('searchFileOrImageDatafield() called with '.$typeclass.' datafield', 0xab627079);


        // ----------------------------------------
        // Need to convert the two arguments into a single key...
        if ( $filename === '' )
            $filename = null;

        if ( is_null($filename) && is_null($has_files) )
            throw new ODRBadRequestException("The filename and has_files arguments to searchFileOrImageDatafield() can't both be null at the same time", 0xab627079);

        if ( !is_null($filename) )
            $has_files = true;

        $key = array(
            'filename' => $filename,
            'has_files' => $has_files
        );
        $key = md5( serialize($key) );


        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$key]) )
            return $cached_searches[$key];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchFileOrImageDatafield(
            $datafield->getDataType()->getId(),
            $datafield->getId(),
            $typeclass,
            $filename,
            $has_files
        );

        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $result
        );

        // ...then recache the search result
        $cached_searches[$key] = $end_result;
        $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Searches the specified file or image template datafield by either the given filename or
     * whether it has any files or not, and returns an array of datarecord ids that match the given
     * criteria.
     *
     * @param DataFields $template_datafield
     * @param string|null $filename
     * @param bool|null $has_files
     *
     * @return array
     */
    public function searchFileOrImageTemplateDatafield($template_datafield, $filename = null, $has_files = null)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $allowed_typeclasses = array(
            'File',
            'Image',
        );
        $typeclass = $template_datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('searchFileOrImageTemplateDatafield() called with '.$typeclass.' datafield', 0xab627079);
        if ( !$template_datafield->getIsMasterField() )
            throw new ODRBadRequestException('searchFileOrImageTemplateDatafield() called with non-master datafield', 0xab627079);


        // ----------------------------------------
        // Need to convert the two arguments into a single key...
        if ( $filename === '' )
            $filename = null;

        if ( is_null($filename) && is_null($has_files) )
            throw new ODRBadRequestException("The filename and has_files arguments to searchFileOrImageTemplateDatafield() can't both be null at the same time", 0xab627079);

        if ( !is_null($filename) )
            $has_files = true;

        $key = array(
            'filename' => $filename,
            'has_files' => $has_files
        );
        $key = md5( serialize($key) );


        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_template_df_'.$template_datafield->getFieldUuid());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$key]) )
            return $cached_searches[$key];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchFileOrImageTemplateDatafield(
            $template_datafield->getFieldUuid(),
            $typeclass,
            $filename,
            $has_files
        );


        // ...then recache the search result
        $cached_searches[$key] = $result;
        $this->cache_service->set('cached_search_template_df_'.$template_datafield->getFieldUuid(), $cached_searches);

        // ...then return it
        return $result;
    }


    /**
     * Searches the specified datafield for the specified value, returning an array of
     * datarecord ids that match the search.
     *
     * @param DataFields $datafield
     * @param string $value
     *
     * @return array
     */
    public function searchTextOrNumberDatafield($datafield, $value)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $allowed_typeclasses = array(
            'IntegerValue',
            'DecimalValue',
            'ShortVarchar',
            'MediumVarchar',
            'LongVarchar',
            'LongText'
        );
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('searchTextOrNumberDatafield() called with '.$typeclass.' datafield', 0x58a164e0);


        // ----------------------------------------
        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$value]) )
            return $cached_searches[$value];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchTextOrNumberDatafield(
            $datafield->getDataType()->getId(),
            $datafield->getId(),
            $typeclass,
            $value
        );

        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $result
        );

        // ...then recache the search result
        $cached_searches[$value] = $end_result;
        $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Searches the specified template datafield for the specified value, and returns an array of
     * datarecord ids that match the given criteria.
     *
     * @param DataFields $template_datafield
     * @param string $value
     *
     * @return array
     */
    public function searchTextOrNumberTemplateDatafield($template_datafield, $value)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $allowed_typeclasses = array(
            'IntegerValue',
            'DecimalValue',
            'ShortVarchar',
            'MediumVarchar',
            'LongVarchar',
            'LongText'
        );
        $typeclass = $template_datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('searchTextOrNumberTemplateDatafield() called with '.$typeclass.' datafield', 0xf902a74c);
        if ( !$template_datafield->getIsMasterField() )
            throw new ODRBadRequestException('searchTextOrNumberTemplateDatafield() called with non-master datafield', 0xf902a74c);


        // ----------------------------------------
        // See if this search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_template_df_'.$template_datafield->getFieldUuid());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$value]) )
            return $cached_searches[$value];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchTextOrNumberTemplateDatafield(
            $template_datafield->getFieldUuid(),
            $typeclass,
            $value
        );


        // ...then recache the search result
        $cached_searches[$value] = $result;
        $this->cache_service->set('cached_search_template_df_'.$template_datafield->getFieldUuid(), $cached_searches);

        // ...then return it
        return $result;
    }


    /**
     * Searches the specified datafield for the specified value, returning an array of
     * datarecord ids that match the search.
     *
     * Split from self::searchTextOrNumberDatafield() because a boolean value technically isn't
     * "number" or "text".  Backend doesn't really care though.
     *
     * @param DataFields $datafield
     * @param bool $value
     *
     * @return array
     */
    public function searchBooleanDatafield($datafield, $value)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'Boolean' )
            throw new ODRBadRequestException('searchBooleanDatafield() called with '.$typeclass.' datafield', 0xdc30095b);


        // ----------------------------------------
        // See if this search result is already cached...
        $boolean_value = 0;
        if ($value)
            $boolean_value = 1;

        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$boolean_value]) )
            return $cached_searches[$boolean_value];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchTextOrNumberDatafield(
            $datafield->getDataType()->getId(),
            $datafield->getId(),
            $typeclass,
            $boolean_value
        );

        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $result
        );

        // ...then recache the search result
        $cached_searches[$value] = $end_result;
        $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Searches the specified template datafield for the specified value, and returns an array of
     * datarecord ids that match the given criteria.
     *
     * Split from self::searchTextOrNumberDatafield() because a boolean value technically isn't
     * "number" or "text".  Backend doesn't really care though.
     *
     * @param DataFields $template_datafield
     * @param bool $value
     *
     * @return array
     */
    public function searchBooleanTemplateDatafield($template_datafield, $value)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $template_datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'Boolean' )
            throw new ODRBadRequestException('searchBooleanTemplateDatafield() called with '.$typeclass.' datafield', 0xab596e67);
        if ( !$template_datafield->getIsMasterField() )
            throw new ODRBadRequestException('searchBooleanTemplateDatafield() called with non-master datafield', 0xab596e67);


        // ----------------------------------------
        // See if this search result is already cached...
        $boolean_value = 0;
        if ($value)
            $boolean_value = 1;

        $cached_searches = $this->cache_service->get('cached_search_template_df_'.$template_datafield->getFieldUuid());
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$boolean_value]) )
            return $cached_searches[$boolean_value];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchTextOrNumberTemplateDatafield(
            $template_datafield->getFieldUuid(),
            $typeclass,
            $boolean_value
        );


        // ...then recache the search result
        $cached_searches[$value] = $result;
        $this->cache_service->set('cached_search_template_df_'.$template_datafield->getFieldUuid(), $cached_searches);

        // ...then return it
        return $result;
    }


    /**
     * Returns an array of datarecord ids that have a value between the given dates.
     *
     * @param DataFields $datafield
     * @param \DateTime|null $before
     * @param \DateTime|null $after
     *
     * @return array
     */
    public function searchDatetimeDatafield($datafield, $before, $after)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'DatetimeValue' )
            throw new ODRBadRequestException('searchDatetimeDatafield() called with '.$typeclass.' datafield', 0x58a164e0);


        // ----------------------------------------
        // TODO - provide the option to search for fields without dates?
        // Determine the keys used to store the lists of datarecords
        $after_key = '>1980-01-01';
        if ( !is_null($after) )
            $after_key = '>'.$after->format('Y-m-d');
        else
            $after = new \DateTime('1980-01-01 00:00:00');

        // Datetime fields use 9999-12-31 as their NULL value...so any search run against them
        //  needs to stop at 9999-12-30 so those NULL values aren't included
        $before_key = '<9999-12-30';
        if ( !is_null($before) )
            $before_key = '<'.$before->format('Y-m-d');
        else
            $before = new \DateTime('9999-12-30 00:00:00');


        // ----------------------------------------
        // Attempt to load the arrays of datarecord ids from the cache...
        $recached = false;
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();


        $before_ids = null;
        if ( !isset($cached_searches[$before_key]) ) {
            // Entry not set, run query to get current results set
            $before_ids = $this->search_query_service->searchDatetimeDatafield(
                $datafield->getId(),
                array(
                    'before' => $before,
                    'after' => new \DateTime('1980-01-01 00:00:00')
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$before_key] = array(
                'records' => $before_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $before_ids = $cached_searches[$before_key]['records'];
        }


        $after_ids = null;
        if ( !isset($cached_searches[$after_key]) ) {
            // Entry not set, run query to get current results set
            $after_ids = $this->search_query_service->searchDatetimeDatafield(
                $datafield->getId(),
                array(
                    'before' => new \DateTime('9999-12-30 00:00:00'),    // value is intentional, see above
                    'after' => $after
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$after_key] = array(
                'records' => $after_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $after_ids = $cached_searches[$after_key]['records'];
        }

        // The intersection between the two arrays is the set of datarecord ids that match the request
        $result = array_intersect_key($before_ids, $after_ids);


        // ----------------------------------------
        // Recache the search result
        if ($recached)
            $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // ...then return it
        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $result
        );

        return $end_result;
    }


    /**
     * Searches the specified datetime template datafield for datarecords that have a value between
     * the given dates.
     *
     * @param DataFields $template_datafield
     * @param \DateTime|null $before
     * @param \DateTime|null $after
     *
     * @return array
     */
    public function searchDatetimeTemplateDatafield($template_datafield, $before, $after)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $typeclass = $template_datafield->getFieldType()->getTypeClass();
        if ( $typeclass !== 'DatetimeValue' )
            throw new ODRBadRequestException('searchDatetimeTemplateDatafield() called with '.$typeclass.' datafield', 0xe89f0f72);
        if ( !$template_datafield->getIsMasterField() )
            throw new ODRBadRequestException('searchDatetimeTemplateDatafield() called with non-master datafield', 0xe89f0f72);


        // ----------------------------------------
        // TODO - provide the option to search for fields without dates?
        // Determine the keys used to store the lists of datarecords
        $after_key = '>1980-01-01';
        if ( !is_null($after) )
            $after_key = '>'.$after->format('Y-m-d');
        else
            $after = new \DateTime('1980-01-01 00:00:00');

        // Datetime fields use 9999-12-31 as their NULL value...so any search run against them
        //  needs to stop at 9999-12-30 so those NULL values aren't included
        $before_key = '<9999-12-30';
        if ( !is_null($before) )
            $before_key = '<'.$before->format('Y-m-d');
        else
            $before = new \DateTime('9999-12-30 00:00:00');


        // ----------------------------------------
        // Attempt to load the arrays of datarecord ids from the cache...
        $recached = false;
        $cached_searches = $this->cache_service->get('cached_search_template_df_'.$template_datafield->getFieldUuid());
        if ( !$cached_searches )
            $cached_searches = array();


        $before_ids = null;
        if ( !isset($cached_searches[$before_key]) ) {
            // Entry not set, run query to get current results set
            $before_ids = $this->search_query_service->searchDatetimeTemplateDatafield(
                $template_datafield->getFieldUuid(),
                array(
                    'before' => $before,
                    'after' => new \DateTime('1980-01-01 00:00:00')
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$before_key] = array(
                'records' => $before_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $before_ids = $cached_searches[$before_key]['records'];
        }


        $after_ids = null;
        if ( !isset($cached_searches[$after_key]) ) {
            // Entry not set, run query to get current results set
            $after_ids = $this->search_query_service->searchDatetimeTemplateDatafield(
                $template_datafield->getFieldUuid(),
                array(
                    'before' => new \DateTime('9999-12-30 00:00:00'),    // value is intentional, see above
                    'after' => $after
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$after_key] = array(
                'records' => $after_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $after_ids = $cached_searches[$after_key]['records'];
        }

        // The intersection between the two arrays is the set of datarecord ids that match the request
        $result = self::templateResultsIntersect($before_ids, $after_ids);


        // ----------------------------------------
        // Recache the search result
        if ($recached)
            $this->cache_service->set('cached_search_template_df_'.$template_datafield->getFieldUuid(), $cached_searches);

        // ...then return it
        return $result;
    }


    /**
     * Returns an array of datarecord ids that have been created between the given dates.
     *
     * @param DataType $datatype
     * @param \DateTime|null $before
     * @param \DateTime|null $after
     *
     * @return array
     */
    public function searchCreatedDate($datatype, $before, $after)
    {
        // ----------------------------------------
        // Determine the keys used to store the lists of datarecords
        $after_key = '>1980-01-01';
        if ( !is_null($after) )
            $after_key = '>'.$after->format('Y-m-d');
        else
            $after = new \DateTime('1980-01-01 00:00:00');

        // Datetime fields use 9999-12-31 as their NULL, but a created date won't have this value
        $before_key = '<9999-12-31';
        if ( !is_null($before) )
            $before_key = '<'.$before->format('Y-m-d');
        else
            $before = new \DateTime('9999-12-31 00:00:00');


        // ----------------------------------------
        // Attempt to load the arrays of datarecord ids from the cache...
        $recached = false;
        $cached_searches = $this->cache_service->get('cached_search_dt_'.$datatype->getId().'_created');
        if ( !$cached_searches )
            $cached_searches = array();


        $before_ids = null;
        if ( !isset($cached_searches[$before_key]) ) {
            // Entry not set, run query to get current results set
            $before_ids = $this->search_query_service->searchCreatedModified(
                $datatype->getId(),
                'created',
                array(
                    'before' => $before,
                    'after' => new \DateTime('1980-01-01 00:00:00')
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$before_key] = array(
                'records' => $before_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $before_ids = $cached_searches[$before_key]['records'];
        }


        $after_ids = null;
        if ( !isset($cached_searches[$after_key]) ) {
            // Entry not set, run query to get current results set
            $after_ids = $this->search_query_service->searchCreatedModified(
                $datatype->getId(),
                'created',
                array(
                    'before' => new \DateTime('9999-12-31 00:00:00'),
                    'after' => $after
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$after_key] = array(
                'records' => $after_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $after_ids = $cached_searches[$after_key]['records'];
        }

        // The intersection between the two arrays is the set of datarecord ids that match the request
        $result = array_intersect_key($before_ids, $after_ids);


        // ----------------------------------------
        // Recache the search result
        if ($recached)
            $this->cache_service->set('cached_search_dt_'.$datatype->getId().'_created', $cached_searches);

        // ...then return it
        $end_result = array(
            'dt_id' => $datatype->getId(),
            'records' => $result
        );

        return $end_result;
    }


    /**
     * Returns an array of datarecord ids that have been modified between the given dates.
     *
     * @param DataType $datatype
     * @param \DateTime|null $before
     * @param \DateTime|null $after
     *
     * @return array
     */
    public function searchModifiedDate($datatype, $before, $after)
    {
        // ----------------------------------------
        // Determine the keys used to store the lists of datarecords
        $after_key = '>1980-01-01';
        if ( !is_null($after) )
            $after_key = '>'.$after->format('Y-m-d');
        else
            $after = new \DateTime('1980-01-01 00:00:00');

        // Datetime fields use 9999-12-31 as their NULL, but a created date won't have this value
        $before_key = '<9999-12-31';
        if ( !is_null($before) )
            $before_key = '<'.$before->format('Y-m-d');
        else
            $before = new \DateTime('9999-12-31 00:00:00');


        // ----------------------------------------
        // Attempt to load the arrays of datarecord ids from the cache...
        $recached = false;
        $cached_searches = $this->cache_service->get('cached_search_dt_'.$datatype->getId().'_modified');
        if ( !$cached_searches )
            $cached_searches = array();


        $before_ids = null;
        if ( !isset($cached_searches[$before_key]) ) {
            // Entry not set, run query to get current results set
            $before_ids = $this->search_query_service->searchCreatedModified(
                $datatype->getId(),
                'modified',
                array(
                    'before' => $before,
                    'after' => new \DateTime('1980-01-01 00:00:00')
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$before_key] = array(
                'records' => $before_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $before_ids = $cached_searches[$before_key]['records'];
        }


        $after_ids = null;
        if ( !isset($cached_searches[$after_key]) ) {
            // Entry not set, run query to get current results set
            $after_ids = $this->search_query_service->searchCreatedModified(
                $datatype->getId(),
                'modified',
                array(
                    'before' => new \DateTime('9999-12-31 00:00:00'),
                    'after' => $after
                )
            );

            // Store the result back in the cache
            $recached = true;
            $cached_searches[$after_key] = array(
                'records' => $after_ids
            );
        }
        else {
            // Entry set, load array of datarecords ids
            $after_ids = $cached_searches[$after_key]['records'];
        }

        // The intersection between the two arrays is the set of datarecord ids that match the request
        $result = array_intersect_key($before_ids, $after_ids);


        // ----------------------------------------
        // Recache the search result
        if ($recached)
            $this->cache_service->set('cached_search_dt_'.$datatype->getId().'_modified', $cached_searches);

        // ...then return it
        $end_result = array(
            'dt_id' => $datatype->getId(),
            'records' => $result
        );

        return $end_result;
    }


    /**
     * Returns an array of datarecord ids that were created by $target_user.
     *
     * @param DataType $datatype
     * @param int $target_user_id
     *
     * @return array
     */
    public function searchCreatedBy($datatype, $target_user_id)
    {
        // ----------------------------------------
        // See if the search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_dt_'.$datatype->getId().'_createdBy');
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$target_user_id]) )
            return $cached_searches[$target_user_id];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchCreatedModified(
            $datatype->getId(),
            'createdBy',
            array(
                'user' => $target_user_id
            )
        );

        $end_result = array(
            'dt_id' => $datatype->getId(),
            'records' => $result
        );


        // ...then recache the search result
        $cached_searches[$target_user_id] = $end_result;
        $this->cache_service->set('cached_search_dt_'.$datatype->getId().'_createdBy', $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Returns an array of datarecord ids that were last modified by $target_user.
     *
     * @param DataType $datatype
     * @param int $target_user_id
     *
     * @return array
     */
    public function searchModifiedBy($datatype, $target_user_id)
    {
        // ----------------------------------------
        // See if the search result is already cached...
        $cached_searches = $this->cache_service->get('cached_search_dt_'.$datatype->getId().'_modifiedBy');
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$target_user_id]) )
            return $cached_searches[$target_user_id];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchCreatedModified(
            $datatype->getId(),
            'modifiedBy',
            array(
                'user' => $target_user_id
            )
        );

        $end_result = array(
            'dt_id' => $datatype->getId(),
            'records' => $result
        );


        // ...then recache the search result
        $cached_searches[$target_user_id] = $end_result;
        $this->cache_service->set('cached_search_dt_'.$datatype->getId().'_modifiedBy', $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Returns an array of datarecord ids that are either public or non-public.
     *
     * @param DataType $datatype
     * @param bool $is_public If true, return the ids of all datarecords that are public
     *                        If false, return the ids of all datarecords that are not public
     *
     * @return array
     */
    public function searchPublicStatus($datatype, $is_public)
    {
        // ----------------------------------------
        // See if the search result is already cached...
        $key = 'public';
        if ( !$is_public )
            $key = 'non_public';

        $cached_searches = $this->cache_service->get('cached_search_dt_'.$datatype->getId().'_public_status');
        if ( !$cached_searches )
            $cached_searches = array();

        if ( isset($cached_searches[$key]) )
            return $cached_searches[$key];


        // ----------------------------------------
        // Otherwise, going to need to run the search again...
        $result = $this->search_query_service->searchPublicStatus(
            $datatype->getId(),
            $is_public
        );

        $end_result = array(
            'dt_id' => $datatype->getId(),
            'records' => $result
        );


        // ...then recache the search result
        $cached_searches[$key] = $end_result;
        $this->cache_service->set('cached_search_dt_'.$datatype->getId().'_public_status', $cached_searches);

        // ...then return it
        return $end_result;
    }


    /**
     * Uses the cached datatree aray to recursively locate every child/linked datatype related to
     * the given datatype id.  Does NOT filter by user permissions.
     *
     * @param int $top_level_datatype_id
     *
     * @return array
     */
    public function getRelatedDatatypes($top_level_datatype_id)
    {
        $datatree_array = $this->dti_service->getDatatreeArray();
        $related_datatypes = self::getRelatedDatatypes_worker($datatree_array, array($top_level_datatype_id) );

        // array_values() to make unique
        return array_unique($related_datatypes);
    }


    /**
     * Uses the cached datatree array to recursively locate every child/linked datatypes related to
     * the datatype ids in the given array.
     *
     * @param array $datatree_array
     * @param array $datatype_ids
     *
     * @return array
     */
    private function getRelatedDatatypes_worker($datatree_array, $datatype_ids)
    {
        $this_level = $datatype_ids;
        $datatype_ids = array_flip($datatype_ids);

        // Find all children of the datatypes specified in $datatype_ids
        $next_level = array();
        foreach ($datatree_array['descendant_of'] as $dt_id => $parent_dt_id) {
            if ( isset($datatype_ids[$parent_dt_id]) )
                $next_level[] = intval($dt_id);
        }

        // Find all datatypes linked to by the datatypes specified in $datatype_ids
        foreach ($datatree_array['linked_from'] as $descendant_id => $ancestor_ids) {
            foreach ($ancestor_ids as $num => $dt_id) {
                if ( isset($datatype_ids[$dt_id]) )
                    $next_level[] = intval($descendant_id);
            }
        }

        if ( count($next_level) > 0 ) {
            $descendant_datatype_ids = self::getRelatedDatatypes_worker($datatree_array, $next_level);
            return array_merge($this_level, $descendant_datatype_ids);
        }
        else {
            return $this_level;
        }
    }


    /**
     * Building/modifying the two search arrays requires knowledge of all datarecords of a given
     * datatype, and their parent datarecords (or ancestors if this is a linked datatype).
     *
     * @param int $datatype_id
     * @param bool $search_as_linked_datatype If true, then the returned array will only contain
     *                                        datarecords of this datatype that have been linked to
     *                                        If false, then the returned array will contain all
     *                                        datarecords of this datatype
     *
     * @return array
     */
    public function getCachedSearchDatarecordList($datatype_id, $search_as_linked_datatype = false)
    {
        // In order to properly build the search arrays, all child/linked datarecords with some
        //  connection to the datatype being searched on need to be located...
        $list = array();

        if (!$search_as_linked_datatype) {
            // The given $datatype_id is either the datatype being searched on, or some child
            //  datatype
            $list = $this->cache_service->get('cached_search_dt_'.$datatype_id.'_dr_parents');
            if (!$list)
                $list = $this->search_query_service->getParentDatarecords($datatype_id);

            $this->cache_service->set('cached_search_dt_'.$datatype_id.'_dr_parents', $list);
        }
        else {
            // The datatype being searched on (irrelevant to this function) somehow links to the
            //  given $datatype_id...since a datarecord could be linked to from multiple ancestor
            //  datarecords (instead of having a single "ancestor" in the case of a child datarecord),
            //  the returned array has a different structure
            $list = $this->cache_service->get('cached_search_dt_'.$datatype_id.'_linked_dr_parents');
            if (!$list)
                $list = $this->search_query_service->getLinkedParentDatarecords($datatype_id);

            $this->cache_service->set('cached_search_dt_'.$datatype_id.'_linked_dr_parents', $list);
        }

        return $list;
    }


    /**
     * Searches on Radio datafields that cross templates need to have a list of all datarecords
     * that also crosses templates...otherwise, they can't cache which RadioOptions are unselected.
     *
     * @param string $template_uuid
     *
     * @return array
     */
    public function getCachedTemplateDatarecordList($template_uuid)
    {
        // Attempt to load the datarecords for this template from the cache...
        $list = $this->cache_service->get('cached_search_template_dt_'.$template_uuid.'_dr_list');
        if (!$list) {
            // ...doesn't exist, need to rebuild
            $query = $this->em->createQuery(
               'SELECT dt.id AS dt_id, dr.id AS dr_id
                FROM ODRAdminBundle:DataType AS mdt
                JOIN ODRAdminBundle:DataType AS dt WITH dt.masterDataType = mdt
                LEFT JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                WHERE mdt.unique_id = :template_uuid
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL
                AND (dr.deletedAt IS NULL OR dr.id IS NULL)'
            )->setParameters(array('template_uuid' => $template_uuid));
            $results = $query->getArrayResult();

            $list = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dr_id = $result['dr_id'];

                // NOTE - despite datafield ids being required for permissions filtering, it
                //  doesn't make sense to load/store them here...the individual template-specific
                //  search functions (currently just the radio search) have to use queries that
                //  will tell them what the datafields are even if nothing matches
                if ( !isset($list[$dt_id]) )
                    $list[$dt_id] = array();

                // It's possible that the derived datatype doesn't have any datarecords
                if ( !is_null($dr_id) )
                    $list[$dt_id][$dr_id] = 1;
            }

            // Store the list back in the cache
            $this->cache_service->set('cached_search_template_dt_'.$template_uuid.'_dr_list', $list);
        }

        return $list;
    }


    /**
     * Returns an array of datafields, organized by public status, for each related datatype.  The
     * datatype's public status is also included, for additional ease of permissions filtering.
     *
     * $searchable_datafields = array(
     *     [<datatype_id>] => array(
     *         'public_date' => <datatype_public_date>,
     *         'datafields' => array(
     *             <public_datafield_id> => <searchable>,
     *             <public_datafield_id> => <searchable>,
     *             ...,
     *             'non_public' => array(
     *                 <non_public_datafield_id> => <searchable>,
     *                 ...
     *             )
     *         )
     *     ),
     *     ...
     * )
     *
     * <searchable> can have four different values...
     * 0: "not searchable" doesn't show up on the search page
     * 1: "general search only" doesn't show up as its on field on the search page, but is searched
     *                          through the "general" textbox
     * 2: "advanced search" shows up as its own field on the search page, and is also searched
     *                      through the "general" textbox
     * 3: "advanced search only" shows up as its own field on the search page, but is not searched
     *                           through the "general" textbox
     *
     * @param int $datatype_id
     *
     * @return array
     */
    public function getSearchableDatafields($datatype_id)
    {
        // ----------------------------------------
        // Going to need all the datatypes related to this given datatype...
        $datatype_id = intval($datatype_id);
        $related_datatypes = self::getRelatedDatatypes($datatype_id);

        // The resulting array depends on the contents of each of the related datatypes
        $searchable_datafields = array();
        foreach ($related_datatypes as $num => $dt_id) {
            $df_list = $this->cache_service->get('cached_search_dt_'.$dt_id.'_datafields');
            if (!$df_list) {
                // If not cached, need to rebuild the list...
                $query = $this->em->createQuery(
                   'SELECT
                        df.id AS df_id, df.fieldUuid AS df_uuid, dfm.publicDate AS df_public_date,
                        dfm.searchable, ft.typeClass,
                        dtm.publicDate AS dt_public_date
                
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                    LEFT JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                    WHERE dt.id = :datatype_id
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL 
                    AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL AND ft.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $dt_id) );
                $results = $query->getArrayResult();

                // If no searchable datafields in this datatype, just continue on to the next
                if (!$results)
                    continue;

                // Only need these once...
                $df_list = array(
                    'dt_public_date' => $results[0]['dt_public_date']->format('Y-m-d'),
                    'datafields' => array(
                        'non_public' => array()
                    )
                );

                // Insert each of the datafields into the array...
                foreach ($results as $result) {
                    // If the datatype doesn't have any datafields, don't attempt to save anything
                    if ( is_null($result['df_id']) )
                        continue;

                    // Inline searching requires the ability to search on any datafield, even those
                    //  the user may not have necessarily marked as "searchable"
                    $searchable = $result['searchable'];
                    $typeclass = $result['typeClass'];

//                    if ( $searchable > 0 ) {
                        $df_id = $result['df_id'];
                        $df_uuid = $result['df_uuid'];    // required for cross-template searching

                        if ($result['df_public_date']->format('Y-m-d') !== '2200-01-01') {
                            $df_list['datafields'][$df_id] = array(
                                'searchable' => $searchable,
                                'typeclass' => $typeclass,
                                'field_uuid' => $df_uuid,
                            );
                        }
                        else {
                            $df_list['datafields']['non_public'][$df_id] = array(
                                'searchable' => $searchable,
                                'typeclass' => $typeclass,
                                'field_uuid' => $df_uuid,
                            );
                        }
//                    }
                }

                // Store the result back in the cache
                $this->cache_service->set('cached_search_dt_'.$dt_id.'_datafields', $df_list);
            }

            // Continue to build up the array of searchable datafields...
            $searchable_datafields[$dt_id] = $df_list;
        }

        return $searchable_datafields;
    }


    /**
     * Similar to self::getSearchableDatafields(), but only stores uuid, typeclass, and searchable
     * information.  This is required to be able to pull off a "general" search across templates,
     * the data stored in the other function is organized by different criteria.
     *
     * @param string $template_uuid
     *
     * @return array
     */
    public function getSearchableTemplateDatafields($template_uuid)
    {
        // ----------------------------------------
        // Going to need all the datatypes related to this given datatype...
        $related_datatypes = self::getRelatedTemplateDatatypes($template_uuid);

        // The resulting array depends on the contents of each of the related datatypes
        $searchable_datafields = array();
        foreach ($related_datatypes as $num => $dt_uuid) {
            $df_list = $this->cache_service->get('cached_search_template_dt_'.$dt_uuid.'_datafields');
            if (!$df_list) {
                // If not cached, need to rebuild the list...
                $query = $this->em->createQuery(
                   'SELECT df.fieldUuid AS df_uuid, dfm.searchable, ft.typeClass
                    FROM ODRAdminBundle:DataType AS dt
                    LEFT JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                    LEFT JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                    WHERE dt.unique_id = :datatype_uuid
                    AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                    AND dfm.deletedAt IS NULL AND ft.deletedAt IS NULL'
                )->setParameters( array('datatype_uuid' => $dt_uuid) );
                $results = $query->getArrayResult();

                // If no searchable datafields in this datatype, just continue on to the next
                if (!$results)
                    continue;

                // Insert each of the datafields into the array...
                $df_list = array();
                foreach ($results as $result) {
                    // If the datatype doesn't have any datafields, don't attempt to save anything
                    if ( is_null($result['df_uuid']) )
                        continue;

                    $searchable = $result['searchable'];
                    $typeclass = $result['typeClass'];
                    $df_uuid = $result['df_uuid'];

                    $df_list[$df_uuid] = array(
                        'searchable' => $searchable,
                        'typeclass' => $typeclass
                    );
                }

                // Store the result back in the cache
                $this->cache_service->set('cached_search_template_dt_'.$dt_uuid.'_datafields', $df_list);
            }

            // Continue to build up the array of searchable datafields...
            $searchable_datafields[$dt_uuid] = $df_list;
        }

        return $searchable_datafields;
    }


    /**
     * In order for general search to be run on a template, ODR needs to have an array of template
     * uuids available for self::getSearchableTemplateDatafields() to work.
     *
     * @param string $top_level_template_uuid
     *
     * @return array
     */
    public function getRelatedTemplateDatatypes($template_uuid)
    {
        // Convert the template uuid into a datatype id if possible...
        /** @var \ODR\AdminBundle\Entity\DataType $datatype */
        $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array(
                'unique_id' => $template_uuid,
                'is_master_type' => 1
            )
        );
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype', false, 0x76e87ad5);

        // Use that id to locate related datatypes from the cached datatree array...
        $related_datatypes = self::getRelatedDatatypes( $datatype->getId() );

        // ...then get the uuids of all the related datatypes
        $query = $this->em->createQuery(
           'SELECT dt.unique_id AS dt_uuid
            FROM ODRAdminBundle:DataType dt
            WHERE dt.id IN (:datatype_ids) AND dt.is_master_type = 1
            AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_ids' => $related_datatypes
            )
        );
        $results = $query->getArrayResult();

        // Will always have at least one result in here...order doesn't matter
        $dt_uuids = array();
        foreach ($results as $result)
            $dt_uuids[] = $result['dt_uuid'];

        return $dt_uuids;
    }
}
