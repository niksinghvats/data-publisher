<?php

/**
 * Open Data Repository Data Publisher
 * Display Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The display controller displays actual record results to the
 * user, executing render plugins as necessary to change how the
 * data looks.  It also handles file and image downloads because
 * of security concerns and routing constraints within Symfony.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class DisplayController extends ODRCustomController
{

    /**
     * Fixes searches to follow the new URL system and redirects the user.
     *
     * @param $datarecord_id
     * @param $search_key
     * @param $offset
     * @param Request $request
     *
     * @return Response
     */
    public function legacy_viewAction($datarecord_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');


            $search_theme_id = 0;
            // Need to reformat to create proper search key and forward internally to view controller

            $search_param_elements = preg_split("/\|/",$search_key);
            $search_params = array();
            foreach($search_param_elements as $search_param_element) {
                $search_param_data = preg_split("/\=/",$search_param_element);
                $search_params[$search_param_data[0]] = $search_param_data[1];
            }
            $new_search_key = $search_key_service->encodeSearchKey($search_params);

            // Generate new style search key from passed search key
            return $search_redirect_service->redirectToViewPage($datarecord_id, $search_theme_id, $new_search_key, $offset);
            /*
            return $this->redirectToRoute(
                "odr_display_view",
                array(
                    'datarecord_id' => $datarecord_id,
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $new_search_key,
                    'offset' => $offset
                )
            );
            */
        }
        catch (\Exception $e) {
            $source = 0x9c453393;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns the "Results" version of the given DataRecord.
     *
     * @param integer $datarecord_id The database id of the datarecord to return.
     * @param integer $search_theme_id
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     *
     * @return Response
     */
    public function viewAction($datarecord_id, $search_theme_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');


            $router = $this->get('router');
            $templating = $this->get('templating');


            // ----------------------------------------
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // TODO - allow rendering of child datarecords?
            if ( $datarecord->getId() !== $datarecord->getGrandparent()->getId() )
                throw new ODRBadRequestException('Not allowed to directly render child datarecords');


            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require a search key to also be set
                if ($search_key == '')
                    throw new ODRBadRequestException('Search theme set without search key');

                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                // TODO - how to recover from this?
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException('The results list from the current search key does not contain datarecord '.$datarecord->getId());
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Store whether the user is permitted to edit at least one datarecord for this datatype
            $can_edit_datatype = $pm_service->canEditDatatype($user, $datatype);
            // Store whether the user is permitted to edit this specific datarecord
            $can_edit_datarecord = $pm_service->canEditDatarecord($user, $datarecord);
            // Store whether the user is permitted to create new datarecords for this datatype
            $can_add_datarecord = $pm_service->canAddDatarecord($user, $datatype);

            if ( !$pm_service->canViewDatatype($user, $datatype) || !$pm_service->canViewDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();


            // Determine whether the user has a restriction on which datarecords they can edit
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);

            // Determine which list of datarecords to pull from the user's session
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');

            // If a datarecord restriction exists, and the user only wants to display editable datarecords...
            $editable_only = false;
            if ( $can_edit_datatype && !is_null($restricted_datarecord_list) && $only_display_editable_datarecords )
                $editable_only = true;


            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            if ($search_key !== '') {
                // Ensure the search key is valid first
                $search_key_service->validateSearchKey($search_key);
                // Determine whether the user is allowed to view this search key
                $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
                if ($filtered_search_key !== $search_key) {
                    // User can't view the results of this search key, redirect to the one they can view
                    return $search_redirect_service->redirectToViewPage($datarecord_id, $search_theme_id, $filtered_search_key, $offset);
                }

                // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
                //  will display stuff in a different order
                $sort_df_id = 0;
                $sort_ascending = true;

                $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
                if ( is_null($sort_criteria) ) {
                    if (is_null($datatype->getSortField())) {
                        // ...this datarecord list is currently ordered by id
                        $odr_tab_service->setSortCriteria($odr_tab_id, 0, 'asc');
                    }
                    else {
                        // ...this datarecord list is ordered by whatever the sort datafield for this datatype is
                        $sort_df_id = $datatype->getSortField()->getId();
                        $odr_tab_service->setSortCriteria($odr_tab_id, $sort_df_id, 'asc');
                    }
                }
                else {
                    // Load the criteria from the user's session
                    $sort_df_id = $sort_criteria['datafield_id'];
                    if ($sort_criteria['sort_direction'] === 'desc')
                        $sort_ascending = false;
                }

                // No problems, so get the datarecords that match the search
                $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions, $sort_df_id, $sort_ascending);
                $original_datarecord_list = $search_results['grandparent_datarecord_list'];


                // ----------------------------------------
                // Determine the correct lists of datarecords to use for rendering...
                $datarecord_list = $original_datarecord_list;
                if ($can_edit_datatype && $editable_only) {
                    // ...user has a restriction list, and only wants to have datarecords in the
                    //  search header that they can edit

                    // array_flip() + isset() is orders of magnitude faster than repeated calls to in_array()
                    $editable_datarecord_list = array_flip($restricted_datarecord_list);
                    foreach ($original_datarecord_list as $num => $dr_id) {
                        if (!isset($editable_datarecord_list[$dr_id]))
                            unset($original_datarecord_list[$num]);
                    }

                    $datarecord_list = array_values($original_datarecord_list);
                }

                // Compute which page of the search results this datarecord is on
                $key = array_search($datarecord->getId(), $datarecord_list);

                $page_length = $odr_tab_service->getPageLength($odr_tab_id);
                $offset = floor($key / $page_length) + 1;

                // Ensure the session has the correct offset stored
                $odr_tab_service->updateDatatablesOffset($odr_tab_id, $offset);
            }


            // ----------------------------------------
            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = null;
            if ($search_key !== '')
                $search_header = $odr_tab_service->getSearchHeaderValues($odr_tab_id, $datarecord->getId(), $datarecord_list);

            // Need this array to exist right now so the part that's not the search header will display
            if ( is_null($search_header) ) {
                $search_header = array(
                    'page_length' => 0,
                    'next_datarecord_id' => 0,
                    'prev_datarecord_id' => 0,
                    'search_result_current' => 0,
                    'search_result_count' => 0
                );
            }

            $redirect_path = $router->generate('odr_display_view', array('datarecord_id' => 0));    // blank path
            $header_html = $templating->render(
                'ODRAdminBundle:Display:display_header.html.twig',
                array(
                    'can_edit_datarecord' => $can_edit_datarecord,
                    'can_add_datarecord' => $can_add_datarecord,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    'odr_tab_id' => $odr_tab_id,

                    // values used by search_header.html.twig
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,
                    'offset' => $offset,

                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord_id'],
                    'prev_datarecord' => $search_header['prev_datarecord_id'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );


            // ----------------------------------------
            // Determine the user's preferred theme
            $theme_id = $theme_service->getPreferredTheme($user, $datatype->getId(), 'master');
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);

            // Render the display page for this datarecord
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            $page_html = $odr_render_service->getDisplayHTML($user, $datarecord, $search_key, $theme);


            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $header_html.$page_html
            );

            // Store which datarecord to scroll to if returning to the search results list
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $source = 0x8f465413;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Starts the process of downloading a file from the server.
     *
     * @param integer $file_id The database id of the file to download.
     * @param Request $request
     *
     * @return Response
     */
    public function filedownloadstartAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $redis_prefix = $this->getParameter('memcached_key_prefix');     // debug purposes only

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // First, ensure user is permitted to download
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewFile($user, $file) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Generate the url for cURL to use
            $pheanstalk = $this->get('pheanstalk');
            $url = $this->generateUrl('odr_crypto_request', array(), UrlGeneratorInterface::ABSOLUTE_URL);

            $api_key = $this->container->getParameter('beanstalk_api_key');

            $file_decryptions = $cache_service->get('file_decryptions');


            // ----------------------------------------
            // Slightly different courses of action depending on the public status of the file
            if ( $file->isPublic() ) {
                // Check that the file exists...
                $local_filepath = realpath( $this->getParameter('odr_web_directory').'/'.$file->getLocalFileName() );
                if (!$local_filepath) {
                    // File does not exist for some reason...see if it's getting decrypted right now
                    $target_filename = 'File_'.$file_id.'.'.$file->getExt();

                    if ( !isset($file_decryptions[$target_filename]) ) {
                        // File is not scheduled to get decrypted at the moment, store that it will be decrypted
                        $file_decryptions[$target_filename] = 1;
                        $cache_service->set('file_decryptions', $file_decryptions);

                        // Schedule a beanstalk job to start decrypting the file
                        $priority = 1024;   // should be roughly default priority
                        $payload = json_encode(
                            array(
                                "object_type" => 'File',
                                "object_id" => $file_id,
                                "target_filename" => $target_filename,
                                "crypto_type" => 'decrypt',

                                "archive_filepath" => '',
                                "desired_filename" => '',

                                "redis_prefix" => $redis_prefix,    // debug purposes only
                                "url" => $url,
                                "api_key" => $api_key,
                            )
                        );

                        //$delay = 1;
                        $delay = 0;
                        $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                    }
                }
                else {
                    // Grab current filesize of file
                    clearstatcache(true, $local_filepath);
                    $current_filesize = filesize($local_filepath);

                    if ( $file->getFilesize() == $current_filesize ) {

                        // File exists and is fully decrypted, determine path to download it
                        $download_url = $this->generateUrl('odr_file_download', array('file_id' => $file_id));

                        // Return a link to the download URL
                        $response = new JsonResponse(array());
                        $response->setStatusCode(200);
                        $response->headers->set('Location', $download_url);

                        return $response;
                    }
                    else {
                        /* otherwise, decryption in progress, do nothing */
                    }
                }
            }
            else {
                // File is not public...see if it's getting decrypted right now
                // Determine the temporary filename for this file
                $target_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
                $target_filename .= '.'.$file->getExt();

                if ( !isset($file_decryptions[$target_filename]) ) {
                    // File is not scheduled to get decrypted at the moment, store that it will be decrypted
                    $file_decryptions[$target_filename] = 1;
                    $cache_service->set('file_decryptions', $file_decryptions);

                    // Schedule a beanstalk job to start decrypting the file
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file_id,
                            "target_filename" => $target_filename,
                            "crypto_type" => 'decrypt',

                            "archive_filepath" => '',
                            "desired_filename" => '',

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    //$delay = 1;
                    $delay = 0;
                    $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                }

                /* otherwise, decryption already in progress, do nothing */
            }

            // Return a URL to monitor decryption progress
            $monitor_url = $this->generateUrl('odr_get_file_decrypt_progress', array('file_id' => $file_id));

            $response = new JsonResponse(array());
            $response->setStatusCode(202);
            $response->headers->set('Location', $monitor_url);

            return $response;
        }
        catch (\Exception $e) {
            $source = 0xcc3f073c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a Symfony response that so browsers can download files from the server.
     *
     * @param integer $file_id The database id of the file to download.
     * @param Request $request
     *
     * @return Response
     */
    public function filedownloadAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // First, ensure user is permitted to download
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewFile($user, $file) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Ensure file exists before attempting to download it
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            if ( !$file->isPublic() )
                $filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId()).'.'.$file->getExt();

            $local_filepath = realpath( $this->getParameter('odr_web_directory').'/'.$file->getUploadDir().'/'.$filename );
            if (!$local_filepath) {
                // If file doesn't exist, and user has permissions...just decrypt it directly?
                // TODO - don't really like this, but downloading a file via table theme or interactive graph feature can't get at non-public files otherwise...
                $local_filepath = $crypto_service->decryptFile($file->getId(), $filename);
            }

            if (!$local_filepath)
                throw new FileNotFoundException($local_filepath);

            $response = self::createDownloadResponse($file, $local_filepath);

            // If the file is non-public, then delete it off the server...despite technically being deleted prior to serving the download, it still works
            if ( !$file->isPublic() && file_exists($local_filepath) )
                unlink($local_filepath);

            return $response;
        }
        catch (\Exception $e) {
            // Usually this'll be called via the jQuery fileDownload plugin, and therefore need a json-format error
            // But in the off-chance it's a direct link, then the error format needs to remain html
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0xe3de488a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates (but does not start) a Symfony StreamedResponse to permit downloading of any size of file.
     *
     * @param File $file
     * @param string $absolute_filepath
     *
     * @throws \Exception
     *
     * @return StreamedResponse
     */
    private function createDownloadResponse($file, $absolute_filepath)
    {
        $response = new StreamedResponse();

        $handle = fopen($absolute_filepath, 'r');
        if ($handle === false)
            throw new \Exception('Unable to open existing file at "'.$absolute_filepath.'"');

        // Attach the original filename to the download
        $display_filename = $file->getOriginalFileName();
        if ($display_filename == null)
            $display_filename = 'File_'.$file->getId().'.'.$file->getExt();

        // Set up a response to send the file back
        $response->setPrivate();
        $response->headers->set('Content-Type', mime_content_type($absolute_filepath));
        $response->headers->set('Content-Length', filesize($absolute_filepath));
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');

        // Have to specify all these properties just so that the last one can be false...otherwise Flow.js can't keep track of the progress
        $response->headers->setCookie(
            new Cookie(
                'fileDownload', // name
                'true',         // value
                0,              // duration set to 'session'
                '/',            // default path
                null,           // default domain
                false,          // don't require HTTPS
                false           // allow cookie to be accessed outside HTTP protocol
            )
        );

        //$response->sendHeaders();

        // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
        $response->setCallback(function () use ($handle) {
            while (!feof($handle)) {
                $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                echo $buffer;
                flush();
            }
            fclose($handle);
        });

        return $response;
    }


    /**
     * Provides users the ability to cancel the decryption of a file.
     * @deprecated
     *
     * @param integer $file_id  The database id of the file currently being decrypted
     * @param Request $request
     *
     * @return Response
     */
    public function cancelfiledecryptAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getEncryptKey() == '')
                throw new ODRException('This File is not ready for download', 503, 0xe8386807);


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Only able to cancel downloads of non-public files...
            if ( !$file->isPublic() ) {

                // Determine the temporary filename being used to store the decrypted file
                $temp_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
                $temp_filename .= '.'.$file->getExt();

                // Ensure that the memcached marker for the decryption of this file does not exist
                $file_decryptions = $cache_service->get('file_decryptions');
                if ($file_decryptions != false && isset($file_decryptions[$temp_filename])) {
                    unset($file_decryptions[$temp_filename]);
                    $cache_service->set('file_decryptions', $file_decryptions);
                }
            }

        }
        catch (\Exception $e) {
            $source = 0x81bed420;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates a Symfony response that so browsers can download images from the server.
     *
     * @param integer $image_id The database_id of the image to download.
     * @param Request $request
     *
     * @return Response
     */
    public function imagedownloadAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Locate the image object in the database
            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');


            // ----------------------------------------
            // Non-Public images are more work because they always need decryption...but first, ensure user is permitted to download
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewImage($user, $image) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // Ensure file exists before attempting to download it
            $filename = 'Image_'.$image_id.'.'.$image->getExt();
            if ( !$image->isPublic() ) {

                $image_path = realpath( $this->getParameter('odr_web_directory').'/'.$filename );     // realpath() returns false if file does not exist
                if ( !$image->isPublic() || !$image_path )
                    $image_path = $crypto_service->decryptImage($image_id, $filename);

                $handle = fopen($image_path, 'r');
                if ($handle === false)
                    throw new FileNotFoundException($image_path);

                // Have to send image headers first...
                $response = new Response();
                $response->setPrivate();
                switch ( strtolower($image->getExt()) ) {
                    case 'gif':
                        $response->headers->set('Content-Type', 'image/gif');
                        break;
                    case 'png':
                        $response->headers->set('Content-Type', 'image/png');
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $response->headers->set('Content-Type', 'image/jpeg');
                        break;
                }

                // Attach the image's original name to the headers...
                $display_filename = $image->getOriginalFileName();
                if ($display_filename == null)
                    $display_filename = 'Image_'.$image_id.'.'.$image->getExt();
                $response->headers->set('Content-Disposition', 'inline; filename="'.$display_filename.'";');

                $response->sendHeaders();

                // After headers are sent, send the image itself
                $im = null;
                switch ( strtolower($image->getExt()) ) {
                    case 'gif':
                        $im = imagecreatefromgif($image_path);
                        imagegif($im);
                        break;
                    case 'png':
                        $im = imagecreatefrompng($image_path);
                        imagepng($im);
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $im = imagecreatefromjpeg($image_path);
                        imagejpeg($im);
                        break;
                }
                imagedestroy($im);

                fclose($handle);

                // If the image isn't public, delete the decrypted version so it can't be accessed without going through symfony
                if ( !$image->isPublic() )
                    unlink($image_path);

                // Return the previously created response
                return $response;
            }
            else {
                // If image is public but doesn't exist, decrypt now
                $image_path = realpath( $this->getParameter('odr_web_directory').'/'.$filename );     // realpath() returns false if file does not exist
                if ( !$image_path )
                    $image_path = $crypto_service->decryptImage($image_id, $filename);

                $url = $this->getParameter('site_baseurl') . '/uploads/images/' . $filename;
                return $this->redirect($url, 301);

            }
        }
        catch (\Exception $e) {
            $source = 0xc2fbf062;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates and renders an HTML list of all files/images that the user is allowed to see in the given datarecord
     *
     * @param integer $grandparent_datarecord_id
     * @param integer $datarecord_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    function listallfilesAction($grandparent_datarecord_id, $datarecord_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $grandparent_datarecord */
            $grandparent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($grandparent_datarecord_id);
            if ($grandparent_datarecord == null)
                throw new ODRNotFoundException('Grandparent Datarecord');

            $grandparent_datatype = $grandparent_datarecord->getDataType();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datarecord');

            if ( ($datarecord_id === 0 && $datafield_id !== 0) || ($datarecord_id !== 0 && $datafield_id === 0) )
                throw new ODRBadRequestException('Must specify either a datarecord or a datafield id');


            /** @var DataType $datatype */
            $datatype = null;

            /** @var DataRecord|null $datarecord */
            $datarecord = null;
            if ($datarecord_id !== 0) {
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
                if ($datarecord == null)
                    throw new ODRNotFoundException('Datarecord');

                $datatype = $datarecord->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }

            /** @var DataFields|null $datafield */
            $datafield = null;
            if ($datafield_id !== 0) {
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
                if ($datafield == null)
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Ensure the user can view the grandparent datarecord/datatype first...
            if ( !$pm_service->canViewDatatype($user, $grandparent_datatype)
                || !$pm_service->canViewDatarecord($user, $grandparent_datarecord)
            ) {
                throw new ODRForbiddenException();
            }

            // If they requested all files in a datarecord, ensure they can view the datarecord
            if ($datarecord != null) {
                if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                    throw new ODRForbiddenException();
            }

            // If they requested all files in a datafield, ensure they can view the datafield
            if ($datafield != null) {
                if ( !$pm_service->canViewDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Get all Datarecords and Datatypes that are associated with the datarecord...need to render an abbreviated view in order to select files
            $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());
            $datatype_array = $dti_service->getDatatypeArray($grandparent_datatype->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // Get rid of all non-file/image datafields while the datarecord array is still "deflated"
            $datafield_ids = array();
            $datatype_ids = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                foreach ($dr['dataRecordFields'] as $df_id => $drf) {
                    if ( count($drf['file']) == 0 /*&& count($drf['image']) == 0*/ )    // TODO - download images in zip too?
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
                    else {
                        $datafield_ids[] = $df_id;
                        $datatype_ids[] = $dr['dataType']['id'];
                    }
                }
            }
            $datafield_ids = array_unique($datafield_ids);
            $datatype_ids = array_unique($datatype_ids);

            // Faster/easier to query the database again to store datafield names
            $query = $em->createQuery(
               'SELECT df.id, dfm.fieldName
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => $datafield_ids) );
            $results = $query->getArrayResult();

            $datafield_names = array();
            foreach ($results as $result) {
                $df_id = $result['id'];
                $df_name = $result['fieldName'];

                $datafield_names[$df_id] = $df_name;
            }

            // Faster/easier to query the database again to store datatype names
            $query = $em->createQuery(
               'SELECT dt.id, dtm.shortName
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatype_ids) );
            $results = $query->getArrayResult();

            $datatype_names = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];
                $dt_name = $result['shortName'];

                $datatype_names[$dt_id] = $dt_name;
            }


            // ----------------------------------------
            // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
            $stacked_datarecord_array[ $grandparent_datarecord->getId() ]
                = $dri_service->stackDatarecordArray($datarecord_array, $grandparent_datarecord->getId());
//print '<pre>'.print_r($stacked_datarecord_array, true).'</pre>';  exit();

            $ret = self::locateFilesforDownloadAll($stacked_datarecord_array, $grandparent_datarecord->getId());
            $stacked_datarecord_array = array($grandparent_datarecord->getId() => $ret);
//print '<pre>'.print_r($stacked_datarecord_array, true).'</pre>';  exit();

            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Default:file_download_dialog_form.html.twig',
                array(
                    'datarecord_id' => $grandparent_datarecord_id,

                    'datarecord_array' => $stacked_datarecord_array,
                    'datafield_names' => $datafield_names,
                    'datatype_names' => $datatype_names,

                    'is_top_level' => true,
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xce2c6ae9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Recursively goes through an "inflated" datarecord array entry and deletes all (child) datarecords that don't have files/images.
     * Assumes that all non-file/image datafields have already been deleted out of the "inflated" array prior to calling this function, so the recursive logic is somewhat simplified.
     * @see parent::stackDatarecordArray()
     *
     * @param array $dr_array         An already "inflated" array of all datarecord entries for this datatype
     * @param integer $datarecord_id  The specific datarecord to check
     *
     * @return null|array
     */
    private function locateFilesforDownloadAll($dr_array, $datarecord_id)
    {
        // Probably going to be deleting entries from $dr_array, so make a copy for looping purposes
        $dr = $dr_array[$datarecord_id];

        if ( count($dr['children']) > 0 ) {
            foreach ($dr['children'] as $child_dt_id => $child_datarecords) {

                foreach ($child_datarecords as $child_dr_id => $child_dr) {
                    // Determine whether this child datarecord has files/images, or has (grand)children with files/images
                    $ret = self::locateFilesforDownloadAll($child_datarecords, $child_dr_id);

                    if ( is_null($ret) ) {
                        // This child datarecord didn't have any files/images, and also didn't have any children of its own with files/images...don't want to see it later
                        unset( $dr_array[$datarecord_id]['children'][$child_dt_id][$child_dr_id] );

                        // If this datarecord has no child datarecords of this child datatype with files/images, then get rid of the entire array entry for the child datatype
                        if ( count($dr_array[$datarecord_id]['children'][$child_dt_id]) == 0 )
                            unset( $dr_array[$datarecord_id]['children'][$child_dt_id] );
                    }
                    else {
                        // Otherwise, save the (probably) modified version of the datarecord entry
                        $dr_array[$datarecord_id]['children'][$child_dt_id][$child_dr_id] = $ret;
                    }
                }
            }
        }

        if ( count($dr_array[$datarecord_id]['children']) == 0 && count($dr_array[$datarecord_id]['dataRecordFields']) == 0 )
            // If the datarecord has no child datarecords, and doesn't have any files/images, return null
            return null;
        else
            // Otherwise, return the (probably) modified version of the datarecord entry
            return $dr_array[$datarecord_id];
    }


    /**
     * Assuming the user has the correct permissions, adds each file from this datarecord/datafield pair into a zip
     * archive and returns that zip archive for download.
     *
     * @param int $grandparent_datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function startdownloadarchiveAction($grandparent_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();

            // Require at least one of these...
            if ( !isset($post['files']) && !isset($post['images']) )
                throw new ODRBadRequestException();

            // Don't need to check whether the file/image ids are numeric...they're not sent to the database
            $file_ids = array();
            if ( isset($post['files']) )
                $file_ids = $post['files'];

            $image_ids = array();
            if ( isset($post['images']) )
                $image_ids = $post['images'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $grandparent_datarecord */
            $grandparent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($grandparent_datarecord_id);
            if ($grandparent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $grandparent_datatype = $grandparent_datarecord->getDataType();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Don't need to verify any permissions, filterByGroupPermissions() will take care of it
            // ----------------------------------------


            // ----------------------------------------
            // Easier/faster to just load the entire datarecord/datatype arrays...
            $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());
            $datatype_array = $dti_service->getDatatypeArray($grandparent_datatype->getId());

            // ...so the permissions service can prevent the user from downloading files/images they're not allowed to see
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // ----------------------------------------
            // Intersect the array of desired file/image ids with the array of permitted files/ids to determine which files/images to add to the zip archive
            $file_list = array();
            $filename_list = array();

            $image_list = array();
            $imagename_list = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                foreach ($dr['dataRecordFields'] as $drf_num => $drf) {
                    if ( count($drf['file']) > 0 ) {
                        foreach ($drf['file'] as $f_num => $f) {
                            if ( in_array($f['id'], $file_ids) ) {
                                // Store by original checksum so multiples of the same file only get decrypted/stored once
                                $original_checksum = $f['original_checksum'];
                                $file_list[$original_checksum] = $f;

                                // Also store the file's name to detect different files with the same filename
                                $filename = $f['fileMeta']['originalFileName'];
                                $filename_list[$original_checksum] = $filename;
                            }
                        }
                    }

                    // TODO - also allow user to download images in a zip archive?
                }
            }


            // If needed, tweak the file list so different files that have the same filename on the server have different filenames in the zip archive
            asort($filename_list);
            $prev_filename = '';
            $num = 2;
            foreach($filename_list as $file_checksum => $filename) {
                if ($filename == $prev_filename) {
                    // This filename maches the previous one...insert a numerical string in this filename to differentiate between the two
                    $file_ext = $file_list[$file_checksum]['ext'];
                    $tmp_filename = substr($filename, 0, strlen($filename)-strlen($file_ext)-1);
                    $tmp_filename .= ' ('.$num.').'.$file_ext;
                    $num++;

                    // Save the new filename back in the array
                    $file_list[$file_checksum]['fileMeta']['originalFileName'] = $tmp_filename;
                }
                else {
                    // This filename is different from the previous one, reset for next potential indentical filename
                    $prev_filename = $filename;
                    $num = 2;
                }
            }

            // TODO - do the same for image names?
/*
print '<pre>'.print_r($file_list, true).'</pre>';
print '<pre>'.print_r($image_list, true).'</pre>';
exit();
*/

            // ----------------------------------------
            // If any files/images remain...
            if ( count($file_list) == 0 && count($image_list) == 0 ) {
                // TODO - what to return?
                $exact = true;
                throw new ODRNotFoundException('No files are available to download', $exact);
            }
            else {
                // Generate the url for cURL to use
                $pheanstalk = $this->get('pheanstalk');
                $url = $this->generateUrl('odr_crypto_request', array(), UrlGeneratorInterface::ABSOLUTE_URL);

                $api_key = $this->container->getParameter('beanstalk_api_key');


                // Create a filename for the zip archive
                $tokenGenerator = $this->get('fos_user.util.token_generator');
                $random_id = substr($tokenGenerator->generateToken(), 0, 12);

                $archive_filename = $random_id.'.zip';
                $archive_filepath = $this->getParameter('odr_web_directory').'/uploads/files/'.$archive_filename;

                $archive_size = count($file_list) + count($image_list);

                foreach ($file_list as $f_checksum => $file) {
                    // Determine the decrypted filename
                    $desired_filename = $file['fileMeta']['originalFileName'];

                    $target_filename = '';
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d') == '2200-01-01' ) {
                        // non-public files need to be decrypted to something difficult to guess
                        $target_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.$user->getId());
                        $target_filename .= '.'.$file['ext'];
                    }
                    else {
                        // public files need to be decrypted to this format
                        $target_filename = 'File_'.$file['id'].'.'.$file['ext'];
                    }

                    // Schedule a beanstalk job to start decrypting the file
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file['id'],
                            "target_filename" => $target_filename,
                            "crypto_type" => 'decrypt',

                            "archive_filepath" => $archive_filepath,
                            "desired_filename" => $desired_filename,

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 0;
                    $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                }
            }

            $return['d'] = array('archive_filename' => $archive_filename, 'archive_size' => $archive_size);
        }
        catch (\Exception $e) {
            $source = 0xc31d45b5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Downloads a zip archive constructed by self::startdownloadarchiveAction()
     *
     * @param string $archive_filename
     * @param Request $request
     *
     * @return Response
     */
    public function downloadarchiveAction($archive_filename, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // TODO - some level of permissions checking?  maybe store archive filename in user's session?

            // Symfony firewall requires $archive_filename to match "0|[0-9a-zA-Z\-\_]{12}.zip"
            if ($archive_filename == '0')
                throw new ODRBadRequestException();

            $archive_filepath = $this->getParameter('odr_web_directory').'/uploads/files/'.$archive_filename;
            if ( !file_exists($archive_filepath) )
                throw new FileNotFoundException($archive_filename);

            $handle = fopen($archive_filepath, 'r');
            if ($handle === false)
                throw new FileNotFoundException($archive_filename);


            // Set up a response to send the file back
            $response = new StreamedResponse();
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($archive_filepath));
            $response->headers->set('Content-Length', filesize($archive_filepath));
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$archive_filename.'";');

            // Have to specify all these properties just so that the last one can be false...otherwise Flow.js can't keep track of the progress
            $response->headers->setCookie(
                new Cookie(
                    'fileDownload', // name
                    'true',         // value
                    0,              // duration set to 'session'
                    '/',            // default path
                    null,           // default domain
                    false,          // don't require HTTPS
                    false           // allow cookie to be accessed outside HTTP protocol
                )
            );

            //$response->sendHeaders();

            // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
            $response->setCallback(function () use ($handle) {
                while (!feof($handle)) {
                    $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                    echo $buffer;
                    flush();
                }
                fclose($handle);
            });

            // Delete the zip archive off the server
            unlink($archive_filepath);

            return $response;
        }
        catch (\Exception $e) {
            // Usually this'll be called via the jQuery fileDownload plugin, and therefore need a json-format error
            // But in the off-chance it's a direct link, then the error format needs to remain html
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0xc953bbf3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

}
