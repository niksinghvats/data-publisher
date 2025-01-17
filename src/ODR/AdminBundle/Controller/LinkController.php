<?php

/**
 * Open Data Repository Data Publisher
 * Link Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles everything required to link/unlink datatypes in design mode, or
 * link/unlink datarecords in edit mode.
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeLinkStatusChangedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Utility\UserUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class LinkController extends ODRCustomController
{

    /**
     * Gets a list of linkable templates for potential use by the clone and link process.
     *
     * This allows a user to clone a database from a template and link to it in a single step.
     *
     * @param $datatype_id
     * @param $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function getclonelinktemplatesAction($datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $local_datatype */
            $local_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($local_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $local_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to link to a remote Datatype outside of the master Theme');

            // Don't allow this in ThemeElements that already have something in them
            if ( $theme_element->getThemeDataFields()->count() > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a non-empty ThemeElement');
            if ( $theme_element->getThemeDataType()->count() > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a non-empty ThemeElement');


            // ----------------------------------------
            // Grab all the ids of all top-level non-metadata templates currently in the database
            // NOTE - the current query does not require templates to have metadata, due to LEFT JOIN
            $query = $em->createQuery(
               'SELECT
                    partial dt.{id, unique_id, is_master_type, created},
                    partial dt_cb.{id, username, email, firstName, lastName},
                    partial dtm.{id, shortName, description, publicDate},
                    partial dt_md.{id}

                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.grandparent AS gp
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.metadata_datatype AS dt_md

                WHERE dt.setup_step = :setup_step AND dt.id = gp.id AND dt.metadata_for IS NULL
                AND dt.is_master_type = (:is_master_type)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND gp.deletedAt IS NULL
                AND (dt_md.id IS NULL OR dt_md.deletedAt IS NULL)'
            )->setParameters(
                array(
                    'setup_step' => DataType::STATE_OPERATIONAL,
                    'is_master_type' => 1
                )
            );
            $results = $query->getArrayResult();

            $linkable_datatypes = array();
            foreach ($results as $dt) {
                // Datatypes should only have one meta entry...
                $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                $dt['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );

                $linkable_datatypes[ $dt['id'] ] = $dt;
            }

            // Ensure user can't clone a template they aren't allowed to view
            foreach ($linkable_datatypes as $dt_id => $dt) {
                // "Manually" determining permissions since PermissionsManagementService requires hydration
                $can_view_datatype = false;
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']) )
                    $can_view_datatype = true;

                $is_public = true;
                if ( $dt['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                    $is_public = false;

                // If the template is not public and the user doesn't have view permissions,
                //  then remove it from the array
                if ( !$is_public && !$can_view_datatype )
                    unset( $linkable_datatypes[$dt_id] );
            }

            // No need to iterate through the remaining templates and run verification checks like
            //  self::willDatatypeLinkRecurse()...if the user chooses to do a clone/link, then the
            //  result will be a brand-new datatype, so there's nothing the renderer can screw up

            // Sort the available templates by name
            usort($linkable_datatypes, function($a, $b) {
                return strnatcasecmp($a['dataTypeMeta']['shortName'], $b['dataTypeMeta']['shortName']);
            });


            // ----------------------------------------
            // Get Templating Object
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Link:clone_link_template_dialog_form.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'theme_element' => $theme_element,

                        'cloneable_templates' => $linkable_datatypes,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x8930415b;
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
     * Parses a $_POST request in order to start the clone and link process.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function cloneandlinkAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
            // TODO - CSRF verification?

            if ( !isset($post['local_datatype_id'])
                || !isset($post['selected_datatype'])
                || !isset($post['theme_element_id']) ) {
                throw new ODRBadRequestException('Invalid Form');
            }

            $local_datatype_id = $post['local_datatype_id'];
            $template_datatype_id = $post['selected_datatype'];
            $theme_element_id = $post['theme_element_id'];

            // Load necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $local_datatype */
            $local_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
            if ( is_null($local_datatype) )
                throw new ODRNotFoundException('Local Datatype');

            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($template_datatype_id);
            if ( is_null($template_datatype) )
                throw new ODRNotFoundException('Template');
            if ( !$template_datatype->getIsMasterType() )
                throw new ODRNotFoundException('Template');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be creating a link to another datatype
            if (!$pm_service->isDatatypeAdmin($user, $local_datatype))
                throw new ODRForbiddenException();

            // Prevent user from linking to a datatype they don't have permissions to view
            if (!$pm_service->canViewDatatype($user, $template_datatype))
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to link to a remote Datatype outside of the master Theme');

            // Ensure the theme_element is empty before attempting to link to a remote datatype
            if ( $theme_element->getThemeDataFields()->count() > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a non-empty ThemeElement');
            if ( $theme_element->getThemeDataType()->count() > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a non-empty ThemeElement');
            if ( $theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a non-empty ThemeElement');

            // Not allowed to link to self
            if ( $local_datatype->getId() === $template_datatype->getId() )
                throw new ODRBadRequestException("A Datatype can't be linked to itself");
            // Not allowed to link to templates that aren't top-level
            if ( $template_datatype->getId() !== $template_datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Not allowed to link to child templates');

            // TODO - get the feeling like there should be more restrictions on what metadata datatypes can link to...
            if ( !is_null($template_datatype->getMetadataFor()) )
                throw new ODRBadRequestException("Not allowed to link to a metadata datatype");
            if ( !is_null($template_datatype->getMetadataDatatype()) && $template_datatype->getMetadataDatatype()->getId() === $local_datatype->getId() )
                throw new ODRBadRequestException("A metadata datatype can't link to the datatype it describes");

            // TODO - others?

            // ----------------------------------------
            // Create a new datatype for the selected template to be cloned into
            $new_datatype = $ec_service->createDatatype($user, 'New Dataset', true);    // don't flush immediately...
            $new_datatype_meta = $new_datatype->getDataTypeMeta();

            // ...clone from the selected template
            $new_datatype->setMasterDataType($template_datatype);
            // ...and attached to the local datatype's template group
            $new_datatype->setTemplateGroup($local_datatype->getTemplateGroup());

            // ...also, default search slug to the new datatype's unique id
            $new_datatype_meta->setSearchSlug($new_datatype->getUniqueId());

            // Flush before continuing...
            $em->persist($new_datatype);
            $em->persist($new_datatype_meta);
            $em->flush();


            // ----------------------------------------
            // Now that the datatype exists, create the background job that will perform the clone
            /** @var TrackedJob $tracked_job */
            $tracked_job = new TrackedJob();
            $tracked_job->setCreatedBy($user);
            $tracked_job->setJobType('clone_and_link');
            $tracked_job->setTotal(2);
            $tracked_job->setCurrent(0);
            $tracked_job->setStarted(new \DateTime());
            $tracked_job->setTargetEntity('datatype_' . $local_datatype_id);
            $tracked_job->setAdditionalData( array() );
            $em->persist($tracked_job);

            // Save all the changes that were made
            $em->flush();
            $em->refresh($tracked_job);


            // Start the job to create the datatype from the template
            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority

            $payload = json_encode(
                array(
                    "user_id" => $user->getId(),
                    "datatype_id" => $new_datatype->getId(),
                    "template_group" => $local_datatype->getTemplateGroup(),

                    "tracked_job_id" => $tracked_job->getId(),
                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "api_key" => $api_key,
                )
            );

            $delay = 0;
            $pheanstalk->useTube('clone_and_link_datatype')->put($payload, $priority, $delay);

            // Return what the javascript needs to set up tracking of job progress
            $return['d'] = array(
                'tracked_job_id' => $tracked_job->getId(),

                'local_datatype_id' => $local_datatype->getId(),
                'new_datatype_id' => $new_datatype->getId(),
                'theme_element_id' => $theme_element->getId(),
            );

            // The javascript will eventually call LinkController::quicklinkdatatypeAction(), which
            //  will deal with updating "master_revision" if needed
        }
        catch (\Exception $e) {
            $source = 0xa1ee8e79;
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
     * Gets a list of datatypes/templates that the given datatype/template can link to.
     *
     * @param integer $datatype_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function getlinkabledatatypesAction($datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $local_datatype */
            $local_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($local_datatype == null)
                throw new ODRNotFoundException('Datatype');
            $is_master_template = $local_datatype->getIsMasterType();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $local_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to modify links to Datatypes outside of the master Theme');

            // Ensure there are no datafields in this theme_element
            if ( $theme_element->getThemeDataFields()->count() > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a ThemeElement that already has Datafields');


            // ----------------------------------------
            // NOTE - when loading datatypes/templates that can be linked to, ODR doesn't need to
            //  immediately care whether the entity calling this is a linked datatype or not.  That
            //  check can happen later when the link is actually being created.


            // ----------------------------------------
            // Locate the previously linked datatype if it exists
            /** @var DataType|null $current_remote_datatype */
            $has_linked_datarecords = false;
            $current_remote_datatype = null;
            if ($theme_element->getThemeDataType()->count() > 0) {
                $current_remote_datatype = $theme_element->getThemeDataType()->first()->getDataType();  // should only ever be one theme_datatype entry

                // Determine whether any datarecords of the local datatype link to datarecords of the remote datatype
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :local_datatype_id AND descendant.dataType = :remote_datatype_id
                    AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datatype_id' => $local_datatype->getId(),
                        'remote_datatype_id' => $current_remote_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                if ( count($results) > 0 )
                    $has_linked_datarecords = true;
            }

            // Going to need the id of the local datatype's grandparent datatype
            $current_datatree_array = $dti_service->getDatatreeArray();
            $grandparent_datatype_id = $local_datatype->getGrandparent()->getId();


            // ----------------------------------------
            // Grab all the ids of all top-level non-metadata datatypes currently in the database
            // NOTE - the current query does not require templates to have metadata, due to LEFT JOIN
            $query = $em->createQuery(
               'SELECT
                    partial dt.{id, unique_id, template_group, is_master_type, created},
                    partial dt_cb.{id, username, email, firstName, lastName},
                    partial dtm.{id, shortName, description, publicDate},
                    partial dt_md.{id}

                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.grandparent AS gp
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.metadata_datatype AS dt_md

                WHERE dt.setup_step = :setup_step AND dt.id = gp.id AND dt.metadata_for IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND gp.deletedAt IS NULL
                AND (dt_md.id IS NULL OR dt_md.deletedAt IS NULL)'
            )->setParameters(
                array(
                    'setup_step' => DataType::STATE_OPERATIONAL
                )
            );
            $results = $query->getArrayResult();

            // TODO - get rid of dtm.shortName and dtm.description
            // TODO - pull info from the metadata datatype somehow...use "cached_datarecord_<dr_id>" probably, loading the data via query here is stupid

            $linkable_datatypes = array();
            foreach ($results as $dt) {
                // Datatypes should only have one meta entry...
                $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                $dt['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );

                // TODO - get the feeling like there should be more restrictions on what metadata datatypes can link to...
                // Don't allow a metadata datatype to link to the datatype it describes
                if ( !is_null($dt['metadata_datatype']) && $dt['metadata_datatype']['id'] === $local_datatype->getId() )
                    continue;

                if ( !$is_master_template ) {
                    // The local datatype is not a master template...don't allow linking to template
                    //  datatypes
                    if ( $dt['is_master_type'] )
                        continue;

                    // Don't allow linking to datatypes that "belong" to another template group...
                    if ( $dt['unique_id'] !== $dt['template_group']) {
                        // ...unless they "belong" to the local datatype's template group
                        if ( $dt['template_group'] !== $local_datatype->getTemplateGroup() )
                            continue;
                    }

                    // Otherwise, this remote datatype is legal to link to
                    $linkable_datatypes[ $dt['id'] ] = $dt;
                }
                else {
                    // The local datatype is a master template...don't allow linking to regular
                    //  datatypes
                    if ( !$dt['is_master_type'] )
                        continue;

                    // Otherwise, this remote datatype is legal to link to
                    $linkable_datatypes[ $dt['id'] ] = $dt;
                }
            }

            // Ensure user can't link to a datatype they aren't allowed to view
            foreach ($linkable_datatypes as $dt_id => $dt) {
                // "Manually" determining permissions since PermissionsManagementService requires hydration
                $can_view_datatype = false;
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']) )
                    $can_view_datatype = true;

                $is_public = true;
                if ( $dt['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                    $is_public = false;

                // If the datatype is not public and the user doesn't have view permissions,
                //  then remove it from the array
                if ( !$is_public && !$can_view_datatype )
                    unset( $linkable_datatypes[$dt_id] );
            }

            // Iterate through the remaining datatype ids to remove ones that the user can't link
            //  to because it would screw up ODR...
            foreach ($linkable_datatypes as $dt_id => $dt) {

                if ( $grandparent_datatype_id == $dt_id ) {
                    // $dt is one of the local datatype's ancestors (or itself, for top-levels)
                    unset( $linkable_datatypes[$dt_id] );
                }
                else if ( isset($current_datatree_array['linked_from'][$dt_id])
                    && in_array($local_datatype->getId(), $current_datatree_array['linked_from'][$dt_id])
                ) {
                    // The local datatype already links to $dt...
                    if ( $current_remote_datatype !== null && $current_remote_datatype->getId() === $dt_id ) {
                        // ...and this is the $theme_element where $local_datatype links to $dt
                        // Need to preserve this entry so that the user can check the existing link
                    }
                    else {
                        // ...otherwise, don't allow the local datatype to link to $dt more than once
                        unset( $linkable_datatypes[$dt_id] );
                    }
                }
                else if ( self::willDatatypeLinkRecurse($current_datatree_array, $local_datatype->getId(), $dt_id) ) {
                    // Also need to block this request if it would cause rendering recursion
                    // e.g. if A is linked to B, then don't allow B to also link to A
                    // e.g. if A links to B, and B to C, then don't allow C to link to A
                    unset( $linkable_datatypes[$dt_id] );
                }

                // Otherwise, linking to this datatype is acceptable
            }


            // Sort the linkable datatypes by name
            usort($linkable_datatypes, function($a, $b) {
                return strnatcasecmp($a['dataTypeMeta']['shortName'], $b['dataTypeMeta']['shortName']);
            });


            // ----------------------------------------
            // Get Templating Object
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Link:link_type_dialog_form.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $current_remote_datatype,
                        'theme_element' => $theme_element,

                        'linkable_datatypes' => $linkable_datatypes,
                        'has_linked_datarecords' => $has_linked_datarecords,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xf8083699;
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
     * Links data types but does not support un-linking.
     *
     * @param integer $local_datatype_id
     * @param integer $remote_datatype_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function quicklinkdatatypeAction($local_datatype_id, $remote_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // All permissions and verifications are taken care of inside self::link_datatype()
            $return = self::link_datatype($local_datatype_id, $remote_datatype_id, '', $theme_element_id);
        }
        catch (\Exception $e) {
            $source = 0x988ee802;
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
     * Parses a $_POST request to create/delete a link from a 'local' DataType to a 'remote'
     * DataType.  If linked, DataRecords of the 'local' DataType will have the option to link to
     * DataRecords of the 'remote' DataType.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function linkdatatypeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
            // TODO - CSRF verification?

            if (!isset($post['local_datatype_id']) || !isset($post['previous_remote_datatype']) || !isset($post['theme_element_id']))
                throw new ODRBadRequestException('Invalid Form');

            $local_datatype_id = $post['local_datatype_id'];
            $previous_remote_datatype_id = $post['previous_remote_datatype'];
            $theme_element_id = $post['theme_element_id'];

            $remote_datatype_id = '';
            if ( isset($post['selected_datatype']) )
                $remote_datatype_id = $post['selected_datatype'];

            // All other permissions and verifications are taken care of inside self::link_datatype()
            $return = self::link_datatype($local_datatype_id, $remote_datatype_id, $previous_remote_datatype_id, $theme_element_id);
        }
        catch (\Exception $e) {
            $source = 0xd2aa5e3e;
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
     * Attempts to remove the link between $local_datatype and $previous_remote_datatype (unless
     * it's empty), in order to create a link between $local_datatype and $remote_datatype (unless
     * that's also empty).
     *
     * @param int $local_datatype_id
     * @param int|null $remote_datatype_id
     * @param int|null $previous_remote_datatype_id
     * @param int $theme_element_id
     *
     * @return array
     */
    private function link_datatype($local_datatype_id, $remote_datatype_id, $previous_remote_datatype_id, $theme_element_id)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            /** @var DataType $local_datatype */
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $local_datatype = $repo_datatype->find($local_datatype_id);
            if ($local_datatype == null)
                throw new ODRNotFoundException('Local Datatype');


            /** @var DataType|null $new_remote_datatype */
            $new_remote_datatype = null;
            if ($remote_datatype_id !== '')
                $new_remote_datatype = $repo_datatype->find($remote_datatype_id);   // Looking to create a link

            /** @var DataType|null $previous_remote_datatype */
            $previous_remote_datatype = null;
            if ( $previous_remote_datatype_id !== '' )
                $previous_remote_datatype = $repo_datatype->find($previous_remote_datatype_id);    // Looking to remove a link

            // Perform various checks to ensure that this link request is valid
            if ($local_datatype_id == $remote_datatype_id)
                throw new ODRBadRequestException("A Datatype can't be linked to itself");
            if ($remote_datatype_id == $previous_remote_datatype_id)
                throw new ODRBadRequestException("Already linked to this Datatype");


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be creating a link to another datatype
            if (!$pm_service->isDatatypeAdmin($user, $local_datatype))
                throw new ODRForbiddenException();

            // Prevent user from linking/unlinking a datatype they don't have permissions to view
            if ( !is_null($new_remote_datatype) && !$pm_service->canViewDatatype($user, $new_remote_datatype) )
                throw new ODRForbiddenException();
            if ( !is_null($previous_remote_datatype) && !$pm_service->canViewDatatype($user, $previous_remote_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to link to a remote Datatype outside of the master Theme');

            // Ensure there are no datafields in this theme_element before attempting to link to a remote datatype
            if ( $theme_element->getThemeDataFields()->count() > 0 )
                throw new ODRBadRequestException('Unable to link a remote Datatype into a ThemeElement that already has Datafields');
            // Ensure the themeElement isn't being used by a RenderPlugin
            if ( $theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new ODRBadRequestException('Unable to link a remote Datatype into a ThemeElement that is being used by a RenderPlugin');

            // Can't throw an error if there's a ThemeDatatype entry, since this function could be
            //  getting called to remove an existing link to a remote datatype


            // TODO - get the feeling like there should be more restrictions on what metadata datatypes can link to...
            if ( !is_null($new_remote_datatype) ) {
                if ( !is_null($new_remote_datatype->getMetadataFor()) )
                    throw new ODRBadRequestException("Not allowed to link to a metadata datatype");
                if ( !is_null($new_remote_datatype->getMetadataDatatype()) && $new_remote_datatype->getMetadataDatatype()->getId() === $local_datatype->getId() )
                    throw new ODRBadRequestException("A metadata datatype can't link to the datatype it describes");
            }


            // ----------------------------------------
            // Going to need these...
            $parent_theme = $theme->getParentTheme();

            // Check whether the user is trying to link/unlink a datatype from another linked datatype
            // i.e where A links to B and B links to C...while on master layout page for A, user
            //  is attempting to unlink C from B, or link from B to D, etc
            $parent_theme_datatype_id = $parent_theme->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $local_datatype->getGrandparent()->getId();

            // ...because linking/unlinking a datatype from another linked datatype needs additional
            //  work done than when linking/unlinking from a regular datatype
            $modifying_linked_datatype = false;
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                $modifying_linked_datatype = true;


            // ----------------------------------------
            // Get the most recent version of the datatree array
            $current_datatree_array = $dti_service->getDatatreeArray();

            if (isset($current_datatree_array['descendant_of'][$remote_datatype_id])
                && $current_datatree_array['descendant_of'][$remote_datatype_id] !== ''
            ) {
                throw new ODRBadRequestException("Not allowed to link to child Datatypes");
            }

            if ($remote_datatype_id == $grandparent_datatype_id) {
                throw new ODRBadRequestException("A Datatype isn't allowed to link to its parent");
            }

            if (isset($current_datatree_array['linked_from'][$remote_datatype_id])
                && in_array($local_datatype_id, $current_datatree_array['linked_from'][$remote_datatype_id])
            ) {
                throw new ODRBadRequestException("Unable to link to the same Datatype multiple times");
            }

            // If a link currently exists...
            if ( !is_null($new_remote_datatype) ) {
                // ...remove it from the array for purposes of finding any recursion
                if ($previous_remote_datatype_id !== '') {
                    $key = array_search(
                        $local_datatype_id,
                        $current_datatree_array['linked_from'][$previous_remote_datatype_id]
                    );
                    unset($current_datatree_array['linked_from'][$previous_remote_datatype_id][$key]);
                }

                // Determine whether this link would cause infinite rendering recursion
                if (self::willDatatypeLinkRecurse($current_datatree_array, $local_datatype_id, $remote_datatype_id))
                    throw new ODRBadRequestException('Unable to link these two datatypes...rendering would become stuck in an infinite loop');
            }


            // ----------------------------------------
            // Now that this link request is guaranteed to be valid...

            // If a previous remote dataype is specified, then the link between the local datatype
            //  and the previous remote datatype needs to be removed...
            if ( !is_null($previous_remote_datatype) ) {
                // Going to mass-delete a pile of stuff...wrap it in a transaction, since DQL doesn't
                //  allow multi-table updates
                $conn = $em->getConnection();
                $conn->beginTransaction();


                // Soft-delete all theme entries linking the local and the remote datatype together
                self::deleteLinkedThemes($em, $user, $local_datatype_id, $previous_remote_datatype_id);

                // Locate and delete all Datatree and LinkedDatatree entries for the previous link
                $datarecords_to_update = self::deleteDatatreeEntries($em, $user, $local_datatype_id, $previous_remote_datatype_id);

                // Mark all Datarecords that used to link to the remote datatype as updated
                self::updateDatarecordEntries($em, $user, $datarecords_to_update, $previous_remote_datatype);

                // Determine whether one of the local datatype's sortfields belongs a remote datatype...
                $query = $em->createQuery(
                   'SELECT dtsf.id
                    FROM ODRAdminBundle:DataTypeSpecialFields AS dtsf
                    LEFT JOIN ODRAdminBundle:DataFields AS remote_df WITH dtsf.dataField = remote_df
                    WHERE dtsf.dataType = :local_datatype_id AND dtsf.field_purpose = :field_purpose
                    AND remote_df.dataType = :remote_datatype_id
                    AND dtsf.deletedAt IS NULL AND remote_df.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datatype_id' => $local_datatype_id,
                        'field_purpose' => DataTypeSpecialFields::SORT_FIELD,
                        'remote_datatype_id' => $previous_remote_datatype_id,
                    )
                );
                $dtsf_ids = $query->getArrayResult();

                if ( !empty($dtsf_ids) ) {
                    // ...if so, then delete that entry
                    $query = $em->createQuery(
                       'UPDATE ODRAdminBundle:DataTypeSpecialFields AS dtsf
                        SET dtsf.deletedAt = :now, dtsf.deletedBy = :deleted_by
                        WHERE dtsf.id IN (:dtsf_ids)
                        AND dtsf.deletedAt IS NULL'
                    )->setParameters(
                        array(
                            'now' => new \DateTime(),
                            'deleted_by' => $user->getId(),
                            'dtsf_ids' => $dtsf_ids,
                        )
                    );
                    $result = $query->execute();

                    // ...and also wipe the sort order for this datatype
                    $cache_service->delete('datatype_'.$local_datatype_id.'_record_order');
                }

                // Ensure that the "master_revision" property gets updated if required
                $needs_flush = false;
                if ( $local_datatype->getIsMasterType() ) {
                    $emm_service->incrementDatatypeMasterRevision($user, $local_datatype, true);    // don't flush immediately
                    $needs_flush = true;
                }

                // Done making mass updates, commit everything
                $conn->commit();

                // Only flush if needed, and only after the previous transaction is committed
                if ($needs_flush)
                    $em->flush();
            }


            // ----------------------------------------
            // If a new remote datatype was specified...
            $using_linked_type = 0;
            if ( !is_null($new_remote_datatype) ) {
                // ...then create a link between the two datatypes
                $using_linked_type = 1;

                $is_link = true;
                $multiple_allowed = true;
                $ec_service->createDatatree($user, $local_datatype, $new_remote_datatype, $is_link, $multiple_allowed);

                // Locate the master theme for the remote datatype
                $source_theme = $theme_info_service->getDatatypeMasterTheme($new_remote_datatype->getId());

                // Create a copy of that theme in this theme element
                $clone_theme_service->cloneIntoThemeElement($user, $theme_element, $source_theme, $new_remote_datatype, 'master');

                // If this linking is happening in a linked datatype...
                // i.e. where A links to B...while user is on master layout page for A, they want to
                //  create a link from B to C
                if ( $modifying_linked_datatype ) {
                    // The previous call to cloneIntoThemeElement() created the required theme data
                    //  from the perspective of A.  However, it needs to be run again to create the
                    //  required theme data so the master layout page for B displays properly...

                    // Need to locate the master theme for "B"...
                    $linked_parent_theme = $theme_info_service->getDatatypeMasterTheme($local_datatype->getId());
                    // ...so a new ThemeElement can be created in it...
                    $linked_theme_element = $ec_service->createThemeElement($user, $linked_parent_theme);
                    // ...so another copy of the remote datatype's theme into that new ThemeElement
                    $clone_theme_service->cloneIntoThemeElement($user, $linked_theme_element, $source_theme, $new_remote_datatype, 'master');
                }

                // Ensure that the "master_revision" property gets updated if required
                if ( $local_datatype->getIsMasterType() )
                    $emm_service->incrementDatatypeMasterRevision($user, $local_datatype, true);    // don't flush immediately

                // A datatype got linked, so any themes that use this master theme as their source
                //  need to get updated themselves
                $properties = array(
                    'sourceSyncVersion' => $theme->getSourceSyncVersion() + 1
                );
                $emm_service->updateThemeMeta($user, $theme, $properties);    // flush here
            }


            // ----------------------------------------
            // If a link got removed or added, the datatype needs to be marked as updated
            if ( !is_null($previous_remote_datatype) || !is_null($new_remote_datatype) ) {
                // ...but the cached datarecord entries only need to be deleted if a link was removed
                $clear_datarecord_caches = false;
                if ( !is_null($previous_remote_datatype) )
                    $clear_datarecord_caches = true;

                try {
                    $event = new DatatypeModifiedEvent($local_datatype, $user, $clear_datarecord_caches);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                try {
                    $event = new DatatypeLinkStatusChangedEvent($local_datatype->getGrandparent(), $new_remote_datatype, $previous_remote_datatype, $user);
                    $dispatcher->dispatch(DatatypeLinkStatusChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                // Mark the ancestor datatype's theme as having been updated
                $theme_info_service->updateThemeCacheEntry($theme, $user);
                // Also delete the list of top-level themes, just incase...
                $cache_service->delete('top_level_themes');
            }


            // ----------------------------------------
            if ($remote_datatype_id === '')
                $remote_datatype_id = $previous_remote_datatype_id;

            // Reload the theme element
            $return['d'] = array(
                'element_id' => $theme_element->getId(),
                'using_linked_type' => $using_linked_type,
                'linked_datatype_id' => $remote_datatype_id,
            );

        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if (!is_null($conn) && $conn->isTransactionActive())
                $conn->rollBack();

            $source = 0xb6e90878;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        return $return;
    }


    /**
     * Returns whether a potential link from $local_datatype_id to $remote_datatype_id would cause
     * infinite loops in the template rendering.
     *
     * @param array $datatree_array
     * @param integer $local_datatype_id   The datatype attempting to become the "local" datatype
     * @param integer $remote_datatype_id  The datatype that could become the "remote" datatype
     *
     * @return boolean
     */
    private function willDatatypeLinkRecurse($datatree_array, $local_datatype_id, $remote_datatype_id)
    {
        // Easiest way to determine whether a link from local_datatype to remote_datatype will
        //  recurse is to see if a cycle emerges by adding said link
        $datatree_array = $datatree_array['linked_from'];

        // 1) Temporarily add a link from local_datatype to remote_datatype
        if ( !isset($datatree_array[$remote_datatype_id]) )
            $datatree_array[$remote_datatype_id] = array();
        if ( !in_array($local_datatype_id, $datatree_array[$remote_datatype_id]) )
            $datatree_array[$remote_datatype_id][] = $local_datatype_id;

        // 2) Treat the datatree array as a graph, and, starting from $remote_datatype_id...
        $is_cyclic = false;
        foreach ($datatree_array[$remote_datatype_id] as $parent_datatype_id) {
            // 3) ...run a depth-first search on the graph to see if a cycle can be located
            if ( isset($datatree_array[$parent_datatype_id]) )
                $is_cyclic = self::datatypeLinkRecursionWorker($datatree_array, $remote_datatype_id, $parent_datatype_id);

            // 4) If a cycle was found, then adding a link from $local_datatype_id to
            //     $remote_datatype_id would cause rendering recursion...therefore, do not allow
            //     this link to be created
            if ($is_cyclic)
                return true;
        }

        // Otherwise, no cycle was found...adding a link from $local_datatype_id to
        //  $remote_datatype_id will not cause rendering recursion
        return false;
    }


    /**
     * Handles the recursive depth-first search needed for self::willDatatypeLinkRecurse()
     *
     * @param array $datatree_array
     * @param integer $target_datatype_id
     * @param integer $current_datatype_id
     *
     * @return boolean
     */
    private function datatypeLinkRecursionWorker($datatree_array, $target_datatype_id, $current_datatype_id)
    {
        $is_cyclic = false;
        foreach ($datatree_array[$current_datatype_id] as $parent_datatype_id) {
            // If $target_datatype_id is in this part of the array, then there's a cycle in this
            //  graph...return true since a link will cause rendering recursion
            if ( $parent_datatype_id == $target_datatype_id )
                return true;

            // ...otherwise, continue the depth-first search
            if ( isset($datatree_array[$parent_datatype_id]) )
                $is_cyclic = self::datatypeLinkRecursionWorker($datatree_array, $target_datatype_id, $parent_datatype_id);

            // If a cycle was found, return true
            if ($is_cyclic)
                return true;
        }

        // Otherwise, no cycles found in this section of the graph
        return false;
    }


    /**
     * Locates all theme entities that link $remote_datatype_id into $local_datatype_id, and
     * deletes them.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param int $local_datatype_id
     * @param int $remote_datatype_id
     */
    private function deleteLinkedThemes($em, $user, $local_datatype_id, $remote_datatype_id)
    {
        $ids_to_delete = array(
            'themes' => array(),
            'theme_elements' => array(),
            'theme_datafields' => array(),
            'theme_datatypes' => array(),
        );

        // Locate all ThemeElements in all Themes across the database where $local_datatype_id
        //  contains a link to $remote_datatype_id
        $query = $em->createQuery(
           'SELECT te.id AS theme_element_id
            FROM ODRAdminBundle:ThemeDataType AS tdt
            JOIN ODRAdminBundle:ThemeElement AS te WITH tdt.themeElement = te
            JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
            WHERE tdt.dataType = :remote_datatype_id AND t.dataType = :local_datatype_id
            AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'remote_datatype_id' => $remote_datatype_id,
                'local_datatype_id' => $local_datatype_id
            )
        );
        $results = $query->getArrayResult();

        // For each of those ThemeElements, build up a list of ids of various theme-related entities
        //  that need to get deleted so rendering doesn't break later on...
        foreach ($results as $result) {
            $te_id = $result['theme_element_id'];
            self::deleteLinkedTheme_worker($em, $te_id, $ids_to_delete);
        }

        // Remove any duplicates from the 'theme_elements' section of the array
        $ids_to_delete['theme_elements'] = array_unique( $ids_to_delete['theme_elements'] );

        // Find all top-level themes that the soon-to-be-deleted themes belong to
        $query = $em->createQuery(
           'SELECT parent.id AS parent_id
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:Theme AS parent WITH t.parentTheme = parent
            WHERE t.id IN (:theme_ids)
            AND t.deletedAt IS NULL AND parent.deletedAt IS NULL'
        )->setParameters( array('theme_ids' => $ids_to_delete['themes']) );
        $results = $query->getArrayResult();

        $top_level_themes = array();
        foreach ($results as $result)
            $top_level_themes[ $result['parent_id'] ] = 1;
        $top_level_themes = array_keys($top_level_themes);


        // ----------------------------------------
        // TODO - should this be in EntityDeletionService?

        // There are six different entities to mark as deleted based on the prior criteria...
        // ...theme entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:Theme AS t
            SET t.deletedAt = :now, t.deletedBy = :deleted_by
            WHERE t.id IN (:theme_ids) AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_ids' => $ids_to_delete['themes'],
            )
        );
        $rows = $query->execute();

        // ...theme_meta entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeMeta AS tm
            SET tm.deletedAt = :now
            WHERE tm.theme IN (:theme_ids) AND tm.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'theme_ids' => $ids_to_delete['themes'],
            )
        );
        $rows = $query->execute();

        // ...theme_element entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeElement AS te
            SET te.deletedAt = :now, te.deletedBy = :deleted_by
            WHERE te.id IN (:theme_element_ids) AND te.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_element_ids' => $ids_to_delete['theme_elements'],
            )
        );
        $rows = $query->execute();

        // ...theme_element_meta entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeElementMeta AS tem
            SET tem.deletedAt = :now
            WHERE tem.themeElement IN (:theme_element_ids) AND tem.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'theme_element_ids' => $ids_to_delete['theme_elements'],
            )
        );
        $rows = $query->execute();

        // ...theme_datafield entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeDataField AS tdf
            SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
            WHERE tdf.id IN (:theme_datafield_ids) AND tdf.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_datafield_ids' => $ids_to_delete['theme_datafields'],
            )
        );
        $rows = $query->execute();

        // ...theme_datatype entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeDataType AS tdt
            SET tdt.deletedAt = :now, tdt.deletedBy = :deleted_by
            WHERE tdt.id IN (:theme_datatype_ids) AND tdt.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_datatype_ids' => $ids_to_delete['theme_datatypes'],
            )
        );
        $rows = $query->execute();


        // ----------------------------------------
        // Cache entries need to be wiped too...
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        foreach ($top_level_themes as $t_id)
            $cache_service->delete('cached_theme_'.$t_id);
    }


    /**
     * Recursively iterates through the given theme_element to determine the ids of all Theme
     * stuff that needs to be marked as deleted when unlinking a linked datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int $theme_element_id
     * @param array $ids_to_delete
     */
    private function deleteLinkedTheme_worker($em, $theme_element_id, &$ids_to_delete)
    {
        $query = $em->createQuery(
           'SELECT partial te.{id}, partial tdt.{id},
               partial c_t.{id}, partial c_te.{id}, partial c_tdf.{id}, partial c_tdt.{id}
            FROM ODRAdminBundle:ThemeElement AS te
            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.childTheme AS c_t
            LEFT JOIN c_t.themeElements AS c_te
            LEFT JOIN c_te.themeDataFields AS c_tdf
            LEFT JOIN c_te.themeDataType AS c_tdt
            WHERE te.id = :theme_element_id
            AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL
            AND c_t.deletedAt IS NULL AND c_te.deletedAt IS NULL
            AND c_tdf.deletedAt IS NULL AND c_tdt.deletedAt IS NULL'
        )->setParameters( array('theme_element_id' => $theme_element_id) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $tdt_id = $result['themeDataType'][0]['id'];
            $ids_to_delete['theme_datatypes'][] = $tdt_id;

            $c_t_id = $result['themeDataType'][0]['childTheme']['id'];
            $ids_to_delete['themes'][] = $c_t_id;

            foreach ($result['themeDataType'][0]['childTheme']['themeElements'] as $te_num => $te) {
                // This will create a duplicate id in the theme_elements section if this theme_element
                //  has a theme_datatype entry...
                $te_id = $te['id'];
                $ids_to_delete['theme_elements'][] = $te_id;

                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $tdf_id = $tdf['id'];
                        $ids_to_delete['theme_datafields'][] = $tdf_id;
                    }
                }

                if ( isset($te['themeDataType']) && isset($te['themeDataType'][0]) )
                    self::deleteLinkedTheme_worker($em, $te_id, $ids_to_delete);
            }
        }
    }


    /**
     * Deletes all datatree and linked_datatree entries linking the given local and remote datatypes,
     * and returns an array of datarecord ids that need updated as a result.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param int $local_datatype_id
     * @param int $remote_datatype_id
     *
     * @return int[]
     */
    private function deleteDatatreeEntries($em, $user, $local_datatype_id, $remote_datatype_id)
    {
        // Locate the Datatree entry tying the local and the remote datatype together, if one exists
        /** @var DataTree $datatree */
        $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
            array(
                'ancestor' => $local_datatype_id,
                'descendant' => $remote_datatype_id
            )
        );

        // If the previously mentioned Datatree entry does exist...
        if ( !is_null($datatree) ) {
            // ...mark the relevant Datatree and DatatreeMeta entities as deleted
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTreeMeta AS dtm
                SET dtm.deletedAt = :now
                WHERE dtm.id IN (:dtm_id) AND dtm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'dtm_id' => $datatree->getDataTreeMeta()->getId()
                )
            );
            $query->execute();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTree AS dt
                SET dt.deletedAt = :now, dt.deletedBy = :user_id
                WHERE dt.id = (:dt_id) AND dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'user_id' => $user->getId(),
                    'dt_id' => $datatree->getId()
                )
            );
            $query->execute();
        }


        // ----------------------------------------
        // Locate all LinkedDatatree entries between the local and the remote datatype
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, ldt.id AS ldt_id
            FROM ODRAdminBundle:DataRecord AS ancestor
            JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
            JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
            WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
            AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters(
            array(
                'ancestor_datatype' => $local_datatype_id,
                'descendant_datatype' => $remote_datatype_id
            )
        );
        $results = $query->getArrayResult();


        // Need to get two lists...one of all the LinkedDatatree entries that need deleting,
        //  and another for all of the ancestor datarecords that need updating
        $ldt_ids = array();
        $datarecord_ids = array();
        foreach ($results as $result) {
            $dr_id = $result['ancestor_id'];
            $ldt_id = $result['ldt_id'];

            $datarecord_ids[] = $dr_id;
            $ldt_ids[] = $ldt_id;
        }
        $datarecord_ids = array_unique($datarecord_ids);

        if ( count($ldt_ids) > 0 ) {
            // Perform a DQL mass update to soft-delete all the LinkedDatatree entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :user_id
                WHERE ldt.id IN (:ldt_ids) AND ldt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'user_id' => $user->getId(),
                    'ldt_ids' => $ldt_ids
                )
            );
            $query->execute();
        }

        // Return a list of datarecord ids that need to get recached
        return $datarecord_ids;
    }


    /**
     * Given a list of datarecord ids compiled by self::deleteDatatreeEntries, marks all those
     * datarecord ids as updated and deletes related cache entries.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param int[] $datarecord_ids
     * @param DataType $previous_remote_datatype
     */
    private function updateDatarecordEntries($em, $user, $datarecord_ids, $previous_remote_datatype)
    {
        // Do NOT want to fire off DatarecordModified events here...it would likely require a lot
        //  of hydration

        // ...since DatarecordModified events aren't being used, that means all relevant datarecords
        //  can be marked as updated with a single DQL statement
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:DataRecord AS dr
            SET dr.updated = :now, dr.updatedBy = :user_id
            WHERE dr.id IN (:datarecord_ids) AND dr.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'user_id' => $user->getId(),
                'datarecord_ids' => $datarecord_ids,
            )
        );
        $query->execute();

        // Clearing the datarecord cache entries is handled by the DatatypeModified event that gets
        //  fired later on

        // Locate and clear all cache entries claiming that a datarecord links to something
        //  in $datarecord_ids
        try {
            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');
            $event = new DatarecordLinkStatusChangedEvent($datarecord_ids, $previous_remote_datatype, $user);
            $dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }
    }


    /**
     * Builds and returns a list of available 'descendant' datarecords to link to from this
     * 'ancestor' datarecord.
     *
     * @param integer $ancestor_datatype_id   The DataType that is being linked from
     * @param integer $descendant_datatype_id The DataType that is being linked to
     * @param integer $local_datarecord_id    The DataRecord being modified.
     * @param integer $search_theme_id
     * @param string $search_key              The current search on this tab
     * @param Request $request
     *
     * @return Response
     */
    public function getlinkabledatarecordsAction($ancestor_datatype_id, $descendant_datatype_id, $local_datarecord_id, $search_theme_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var TableThemeHelperService $tth_service */
            $tth_service = $this->container->get('odr.table_theme_helper_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Grab the datatypes from the database
            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ($local_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $local_datatype = $local_datarecord->getDataType();
            if ($local_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Local Datatype');

            // $local_datatype_id = $local_datatype->getId();

            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ($ancestor_datatype == null)
                throw new ODRNotFoundException('Ancestor Datatype');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ($descendant_datatype == null)
                throw new ODRNotFoundException('Descendant Datatype');

            // Ensure a link exists from ancestor to descendant datatype
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $ancestor_datatype->getId(),
                    'descendant' => $descendant_datatype->getId()
                )
            );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');

            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require a search key to also be set
                if ($search_key == '')
                    throw new ODRBadRequestException();

                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
//                if ($search_theme->getDataType()->getId() !== $local_datatype->getId())
//                    throw new ODRBadRequestException();
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewDatatype($user, $ancestor_datatype) )
                throw new ODRForbiddenException();

            // TODO - create a new permission specifically for linking/unlinking datarecords?
            if ( !$pm_service->canEditDatarecord($user, $local_datarecord) )
                throw new ODRForbiddenException();

            if ( !$pm_service->canViewDatatype($user, $descendant_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Determine which datatype we're trying to create a link with
            $local_datarecord_is_ancestor = false;
            $local_datatype = $local_datarecord->getDataType();
            $remote_datatype = null;
            if ($local_datatype->getId() == $ancestor_datatype_id) {
                $remote_datatype = $repo_datatype->find($descendant_datatype_id);   // Linking to a remote datarecord from this datarecord
                $local_datarecord_is_ancestor = true;
            }
            else {
                $remote_datatype = $repo_datatype->find($ancestor_datatype_id);     // Getting a remote datarecord to link to this datarecord
                $local_datarecord_is_ancestor = false;
            }
            /** @var DataType $remote_datatype */

            // Ensure the remote datatype has a suitable theme...
            if ($remote_datatype->getSetupStep() != DataType::STATE_OPERATIONAL)
                throw new ODRBadRequestException('Unable to link to Remote Datatype');

            // Since the above statement didn't throw an exception, the one below shouldn't either...
            $theme_id = $theme_info_service->getPreferredThemeId($user, $remote_datatype->getId(), 'search_results');    // TODO - do I actually want a separate page type for linking purposes?

            // Create a base search key for the remote datatype, so the search sidebar can be used
            $remote_datatype_search_key = $search_key_service->encodeSearchKey(
                array(
                    'dt_id' => $remote_datatype->getId()
                )
            );


            // ----------------------------------------
            // Grab all datarecords currently linked to the local_datarecord
            $linked_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                // local_datarecord is on the ancestor side of the link
                $query = $em->createQuery(
                   'SELECT descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor = :local_datarecord AND descendant.dataType = :remote_datatype
                    AND descendant.provisioned = false
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datarecord' => $local_datarecord->getId(),
                        'remote_datatype' => $remote_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                foreach ($results as $num => $data) {
                    $descendant_id = $data['descendant_id'];
                    if ( $descendant_id == null || trim($descendant_id) == '' )
                        continue;

                    $linked_datarecords[ $descendant_id ] = 1;
                }
            }
            else {
                // local_datarecord is on the descendant side of the link
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant = :local_datarecord AND ancestor.dataType = :remote_datatype
                    AND ancestor.provisioned = false
                    AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datarecord' => $local_datarecord->getId(),
                        'remote_datatype' => $remote_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                foreach ($results as $num => $data) {
                    $ancestor_id = $data['ancestor_id'];
                    if ( $ancestor_id == null || trim($ancestor_id) == '' )
                        continue;

                    $linked_datarecords[ $ancestor_id ] = 1;
                }
            }

            // ----------------------------------------
            // Store whether the link allows multiples or not
            $datatree_array = $dti_service->getDatatreeArray();

            $allow_multiple_links = false;
            if ( isset($datatree_array['multiple_allowed'][$descendant_datatype->getId()])
                && in_array(
                    $ancestor_datatype->getId(),
                    $datatree_array['multiple_allowed'][$descendant_datatype->getId()]
                )
            ) {
                $allow_multiple_links = true;
            }

            // ----------------------------------------
            // Determine which, if any, datarecords can't be linked to because doing so would
            //  violate the "multiple_allowed" rule
            $illegal_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                /* do nothing...the "multiple_allowed" rule will be enforced elsewhere */
            }
            else if (!$allow_multiple_links) {
                // If linking from descendant side, and link is setup to only allow to linking to a
                //  single descendant, then determine which datarecords on the ancestor side
                //  already have links to datarecords on the descendant side
                $query = $em->createQuery(
                   'SELECT ancestor.id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant.dataType = :descendant_datatype AND ancestor.dataType = :ancestor_datatype
                    AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'descendant_datatype' => $descendant_datatype->getId(),
                        'ancestor_datatype' => $ancestor_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                foreach ($results as $num => $result) {
                    $dr_id = $result['id'];
                    $illegal_datarecords[$dr_id] = 1;
                }
            }


            // ----------------------------------------
            // Convert the list of linked datarecords into a slightly different format so the datatables plugin can use it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            $table_html = $tth_service->getRowData($user, $datarecord_list, $remote_datatype->getId(), $theme_id);
            $table_html = json_encode($table_html);

            // Grab the column names for the datatables plugin
            $column_data = $tth_service->getColumnNames($user, $remote_datatype->getId(), $theme_id);
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];

            // Render the dialog box for this request
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Link:link_datarecord_form.html.twig',
                    array(
                        'search_theme_id' => $search_theme_id,
                        'search_key' => $search_key,
                        'remote_datatype_search_key' => $remote_datatype_search_key,

                        'local_datarecord' => $local_datarecord,
                        'local_datarecord_is_ancestor' => $local_datarecord_is_ancestor,
                        'ancestor_datatype' => $ancestor_datatype,
                        'descendant_datatype' => $descendant_datatype,

                        'allow_multiple_links' => $allow_multiple_links,
                        'linked_datarecords' => $linked_datarecords,
                        'illegal_datarecords' => $illegal_datarecords,

                        'count' => count($linked_datarecords),
                        'table_html' => $table_html,
                        'column_names' => $column_names,
                        'num_columns' => $num_columns,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x30878efd;
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
     * Parses a $_POST request to update the links between a 'local' datarecord and some number of
     *  'remote' datarecords.
     *
     * The $datarecords variable from the POST request contains the ids of remote datarecords that
     *  the local datarecord should be linked to...any records that the local datarecord is currently
     *  linked to, but are not listed in $datarecords, will be unlinked.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function linkdatarecordsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();

            if ( !isset($post['local_datarecord_id']) || !isset($post['ancestor_datatype_id']) || !isset($post['descendant_datatype_id']))
                throw new ODRBadRequestException();

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
            $datarecords = array();
            if ( isset($post['datarecords']) ) {
                if ( isset($post['post_type']) && $post['post_type'] == 'JSON' ) {
                    foreach ($post['datarecords'] as $index => $data) {
                        $datarecords[$data] = $data;
                    }
                }
                else {
                    $datarecords = $post['datarecords'];
                }
            }

            // The "search" linking sends this controller action a list of datarecords that should
            //  remain linked...requiring the removal of records not listed in the post request
            $remove_records_not_in_post = true;
            if ( isset($post['post_action']) && $post['post_action'] == 'ADD_ONLY' ) {
                // The "inline" linking only sends the id of a single datarecord that should be
                //  linked...so this controller action should not attempt to do cleanup
                $remove_records_not_in_post = false;
            }


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ($local_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $local_datatype = $local_datarecord->getDataType();
            if ($local_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Local Datatype');
            $local_datatype_id = $local_datatype->getId();


            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ($ancestor_datatype == null)
                throw new ODRNotFoundException('Ancestor Datatype');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ($descendant_datatype == null)
                throw new ODRNotFoundException('Descendant Datatype');

            // Ensure a link exists from ancestor to descendant datatype
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $ancestor_datatype->getId(),
                    'descendant' => $descendant_datatype->getId()
                )
            );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');

            // Determine which datatype is the remote one
            $remote_datatype_id = $descendant_datatype_id;
            if ($local_datatype_id == $descendant_datatype_id)
                $remote_datatype_id = $ancestor_datatype_id;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            $can_view_ancestor_datatype = $pm_service->canViewDatatype($user, $ancestor_datatype);
            $can_view_descendant_datatype = $pm_service->canViewDatatype($user, $descendant_datatype);
            $can_view_local_datarecord = $pm_service->canViewDatarecord($user, $local_datarecord);
            $can_edit_ancestor_datarecord = $pm_service->canEditDatatype($user, $ancestor_datatype);

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !$can_view_ancestor_datatype || !$can_view_descendant_datatype || !$can_view_local_datarecord || !$can_edit_ancestor_datarecord )
                throw new ODRForbiddenException();


            // Need to also check whether user has view permissions for remote datatype...
            $can_view_remote_datarecords = false;
            if ( isset($datatype_permissions[$remote_datatype_id]) && isset($datatype_permissions[$remote_datatype_id]['dr_view']) )
                $can_view_remote_datarecords = true;

            if (!$can_view_remote_datarecords) {
                // User apparently doesn't have view permissions for the remote datatype...prevent them from touching a non-public datarecord in that datatype
                $remote_datarecord_ids = array();
                foreach ($datarecords as $id => $num)
                    $remote_datarecord_ids[] = $id;

                // Determine whether there are any non-public datarecords in the list that the user wants to link...
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                    WHERE dr.id IN (:datarecord_ids) AND drm.publicDate = :public_date
                    AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'datarecord_ids' => $remote_datarecord_ids,
                        'public_date' => "2200-01-01 00:00:00"
                    )
                );
                $results = $query->getArrayResult();

                // ...if there are, then prevent the action since the user isn't allowed to see them
                if ( count($results) > 0 )
                    throw new ODRForbiddenException();
            }
            else {
                /* user can view remote datatype, no other checks needed */
            }
            // --------------------


            // Ensure these actions are undertaken on the correct entity
            $local_datarecord_is_ancestor = true;
            if ($local_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                $local_datarecord_is_ancestor = false;
            }

            // Load all records currently linked to the local_datarecord
            $local_relation = 'ancestor';
            $remote_relation = 'descendant';
            if ( !$local_datarecord_is_ancestor ) {
                $local_relation = 'descendant';
                $remote_relation = 'ancestor';
            }

            $query = $em->createQuery(
               'SELECT ldt
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                LEFT JOIN ODRAdminBundle:DataRecord AS remote_dr WITH ldt.'.$remote_relation.' = remote_dr
                WHERE ldt.'.$local_relation.' = :datarecord AND remote_dr.dataType = :remote_datatype_id
                AND ldt.deletedAt IS NULL AND remote_dr.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datarecord' => $local_datarecord->getId(),
                    'remote_datatype_id' => $remote_datatype_id
                )
            );
            $results = $query->getResult();

            $linked_datatree = array();
            foreach ($results as $num => $ldt)
                $linked_datatree[] = $ldt;
            /** @var LinkedDataTree[] $linked_datatree */


            // ----------------------------------------
            // Need to determine whether this linking request ends up violating the "multiple_allowed"
            //  property of the datatree entry
            if ( !$datatree->getMultipleAllowed() ) {
                // This only matters when the datatree allows a single link/child
                if ( !$remove_records_not_in_post ) {
                    // The request is attempting to create a link to at least one record
                    $existing_links_count = count($linked_datatree);
                    $new_links_count = count($datarecords);
                    if ( ($existing_links_count + $new_links_count) > 1 )
                        throw new ODRBadRequestException('The relationship between "'.$ancestor_datatype->getShortName().'" and "'.$descendant_datatype->getShortName().'" only allows a single linked record, but this save request would exceed that number');
                }
                else {
                    // Otherwise, the request could both delete existing links and create new ones
                    $datarecords_to_link = $datarecords;
                    $count_after_unlinking = count($linked_datatree);

                    foreach ($linked_datatree as $ldt) {
                        $remote_datarecord = null;
                        if ($local_datarecord_is_ancestor)
                            $remote_datarecord = $ldt->getDescendant();
                        else
                            $remote_datarecord = $ldt->getAncestor();

                        if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
                            // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                            continue;
                        }
                        else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                            // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                            continue;
                        }

                        // If a descendant datarecord...
                        if ( isset($datarecords_to_link[$remote_datarecord->getId()]) ) {
                            // ...is in the post request, then it was linked before, and will
                            //  continue to be linked
                            unset( $datarecords_to_link[$remote_datarecord->getId()] );
                        }
                        else {
                            // ...is not in the post request, then it will be unlinked
                            $count_after_unlinking--;
                        }
                    }

                    if ( (count($datarecords_to_link) + $count_after_unlinking) > 1 )
                        throw new ODRBadRequestException('The relationship between "'.$ancestor_datatype->getShortName().'" and "'.$descendant_datatype->getShortName().'" only allows a single linked record, but this save request would exceed that number');
                }
            }


            // ----------------------------------------
            // Keep track of whether any change was made
            $change_made = false;

            // Likely going to need to clear cache entries for multiple records
            $records_needing_events = array();

            if ($remove_records_not_in_post) {
                foreach ($linked_datatree as $ldt) {
                    $remote_datarecord = null;
                    if ($local_datarecord_is_ancestor)
                        $remote_datarecord = $ldt->getDescendant();
                    else
                        $remote_datarecord = $ldt->getAncestor();

                    if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
                        // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                        continue;
                    } else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                        // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                        continue;
                    }

                    // If a descendant datarecord isn't listed in $datarecords, it got unlinked
                    if ( !isset($datarecords[$remote_datarecord->getId()]) ) {
                        // print 'removing link between ancestor datarecord '.$ldt->getAncestor()->getId().' and descendant datarecord '.$ldt->getDescendant()->getId()."\n";

                        // Setup for figuring out which cache entries need deleted
                        $gp_dr = $ldt->getAncestor()->getGrandparent();
                        $records_needing_events[ $gp_dr->getId() ] = $gp_dr;

                        // Delete the linked_datatree entry
                        $ldt->setDeletedBy($user);
                        $ldt->setDeletedAt(new \DateTime());
                        $em->persist($ldt);

                        // The local record is no longer linked to this remote record
                        $change_made = true;

                        // NOTE: don't want to fire off an event right this moment...there could be
                        //  multiple records to be unlinked, and even more records to link to
                    }
                    else {
                        // Otherwise, a datarecord was linked and still is linked...
                        unset( $datarecords[$remote_datarecord->getId()] );
                        // print 'link between local datarecord '.$local_datarecord->getId().' and remote datarecord '.$remote_datarecord->getId()." already exists\n";
                    }
                }

                // If the local datatype is using a sortfield that comes from the remote datatype,
                //  then need to wipe the local datatype's default sort ordering
                $query = $em->createQuery(
                   'SELECT dtsf.id
                    FROM ODRAdminBundle:DataTypeSpecialFields AS dtsf
                    LEFT JOIN ODRAdminBundle:DataFields AS remote_df WITH dtsf.dataField = remote_df
                    WHERE dtsf.dataType = :local_datatype_id AND dtsf.field_purpose = :field_purpose
                    AND remote_df.dataType = :remote_datatype_id
                    AND dtsf.deletedAt IS NULL AND remote_df.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datatype_id' => $local_datatype_id,
                        'field_purpose' => DataTypeSpecialFields::SORT_FIELD,
                        'remote_datatype_id' => $remote_datatype_id,
                    )
                );
                $dtsf_ids = $query->getArrayResult();

                if ( !empty($dtsf_ids) )
                    $cache_service->delete('datatype_'.$local_datatype_id.'_record_order');

                // Flush once everything is deleted
                $em->flush();
            }


            // ----------------------------------------
            // Anything remaining in $datarecords is a newly linked datarecord
            foreach ($datarecords as $id => $num) {
                // Must be a valid record
                $remote_datarecord = $repo_datarecord->find($id);
                if ($remote_datarecord === null)
                    throw new ODRForbiddenException();


                // For readability, translate local/remote datarecord into ancestor/descendant
                $ancestor_datarecord = null;
                $descendant_datarecord = null;
                if ($local_datarecord_is_ancestor) {
                    $ancestor_datarecord = $local_datarecord;
                    $descendant_datarecord = $remote_datarecord;
                    // print 'ensuring link from local datarecord '.$local_datarecord->getId().' to remote datarecord '.$remote_datarecord->getId()."\n";
                }
                else {
                    $ancestor_datarecord = $remote_datarecord;
                    $descendant_datarecord = $local_datarecord;
                    // print 'ensuring link from remote datarecord '.$remote_datarecord->getId().' to local datarecord '.$local_datarecord->getId()."\n";
                }

                // Ensure there is a link between the two datarecords
                $ec_service->createDatarecordLink($user, $ancestor_datarecord, $descendant_datarecord);

                // Setup for figuring out which cache entries need deleted
                $gp_dr = $ancestor_datarecord->getGrandparent();
                $records_needing_events[ $gp_dr->getId() ] = $gp_dr;

                // The local record is now linked to this remote record
                $change_made = true;
            }

            // Done modifying the database
            $em->flush();


            // ----------------------------------------
            // Each of the records in $records_needing_events needs to be marked as updated and have
            //  their primary cache entries cleared
            try {
                foreach ($records_needing_events as $dr_id => $dr) {
                    $event = new DatarecordModifiedEvent($dr, $user);
                    $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                }
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Each of these records also needs to have their "associated_datarecords_for_<dr_id>"
            //  cache entry deleted so the view/edit pages can show the correct linked records
            $records_to_clear = array_keys($records_needing_events);

            try {
                $event = new DatarecordLinkStatusChangedEvent($records_to_clear, $descendant_datatype, $user);
                $dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            $return['d'] = array(
                'datatype_id' => $descendant_datatype->getId(),
                'datarecord_id' => $local_datarecord->getId(),

                'change_made' => $change_made,
            );
        }
        catch (\Exception $e) {
            $source = 0x5392e9e1;
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
     * Parses a $_POST request to delete the links between a 'local' datarecord and some number of
     *  'remote' datarecords.
     *
     * Unlike self::linkdatarecordsAction(), the $datarecords variable from the POST request contains
     *  ids of remote datarecords that will be unlinked.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function unlinkrecordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();

            if ( !isset($post['local_datarecord_id']) || !isset($post['ancestor_datatype_id']) || !isset($post['descendant_datatype_id']))
                throw new ODRBadRequestException();

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
            $datarecords = array();
            if ( isset($post['datarecords']) ) {
                if ( isset($post['post_type']) && $post['post_type'] == 'JSON' ) {
                    foreach ($post['datarecords'] as $index => $data) {
                        $datarecords[$data] = $data;
                    }
                }
                else {
                    $datarecords = $post['datarecords'];
                }
            }


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ($local_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $local_datatype = $local_datarecord->getDataType();
            if ($local_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Local Datatype');
            $local_datatype_id = $local_datatype->getId();


            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ($ancestor_datatype == null)
                throw new ODRNotFoundException('Ancestor Datatype');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ($descendant_datatype == null)
                throw new ODRNotFoundException('Descendant Datatype');

            // Ensure a link exists from ancestor to descendant datatype
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $ancestor_datatype->getId(), 'descendant' => $descendant_datatype->getId()) );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');

            // Determine which datatype is the remote one
            $remote_datatype_id = $descendant_datatype_id;
            if ($local_datatype_id == $descendant_datatype_id)
                $remote_datatype_id = $ancestor_datatype_id;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            $can_view_ancestor_datatype = $pm_service->canViewDatatype($user, $ancestor_datatype);
            $can_view_descendant_datatype = $pm_service->canViewDatatype($user, $descendant_datatype);
            $can_view_local_datarecord = $pm_service->canViewDatarecord($user, $local_datarecord);
            $can_edit_ancestor_datarecord = $pm_service->canEditDatatype($user, $ancestor_datatype);

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !$can_view_ancestor_datatype || !$can_view_descendant_datatype || !$can_view_local_datarecord || !$can_edit_ancestor_datarecord )
                throw new ODRForbiddenException();


            // Need to also check whether user has view permissions for remote datatype...
            $can_view_remote_datarecords = false;
            if ( isset($datatype_permissions[$remote_datatype_id]) && isset($datatype_permissions[$remote_datatype_id]['dr_view']) )
                $can_view_remote_datarecords = true;

            if (!$can_view_remote_datarecords) {
                // User apparently doesn't have view permissions for the remote datatype...prevent them from touching a non-public datarecord in that datatype
                $remote_datarecord_ids = array();
                foreach ($datarecords as $id => $num)
                    $remote_datarecord_ids[] = $id;

                // Determine whether there are any non-public datarecords in the list that the user wants to link...
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                    WHERE dr.id IN (:datarecord_ids) AND drm.publicDate = "2200-01-01 00:00:00"
                    AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
                )->setParameters( array('datarecord_ids' => $remote_datarecord_ids) );
                $results = $query->getArrayResult();

                // ...if there are, then prevent the action since the user isn't allowed to see them
                if ( count($results) > 0 )
                    throw new ODRForbiddenException();
            }
            else {
                /* user can view remote datatype, no other checks needed */
            }
            // --------------------

            // Ensure these actions are undertaken on the correct entity
            $linked_datatree = null;
            $local_datarecord_is_ancestor = true;
            if ($local_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                $local_datarecord_is_ancestor = false;
            }

            // Load all records currently linked to the local_datarecord
            $remote = 'ancestor';
            if (!$local_datarecord_is_ancestor)
                $remote = 'descendant';

            $query = $em->createQuery(
               'SELECT ldt
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                WHERE ldt.'.$remote.' = :datarecord
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $local_datarecord->getId()) );
            $results = $query->getResult();

            $linked_datatree = array();
            foreach ($results as $num => $ldt)
                $linked_datatree[] = $ldt;
            /** @var LinkedDataTree[] $linked_datatree */


            // ----------------------------------------
            // This controller action won't ever violate the "multiple_allowed" property of the
            //  datatree entry

            // ----------------------------------------
            // Going to need to clear the "associated_datarecords_for_<dr_id>" cache entry for
            //  potentially multiple datarecords...
            $records_needing_events = array();

            foreach ($linked_datatree as $ldt) {
                $remote_datarecord = null;
                if ($local_datarecord_is_ancestor)
                    $remote_datarecord = $ldt->getDescendant();
                else
                    $remote_datarecord = $ldt->getAncestor();

                if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
                    // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                    continue;
                } else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                    // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                    continue;
                }

                // If a descendant datarecord is listed in $datarecords, it got unlinked
                if ( isset($datarecords[$remote_datarecord->getId()]) ) {
                    // print 'removing link between ancestor datarecord '.$ldt->getAncestor()->getId().' and descendant datarecord '.$ldt->getDescendant()->getId()."\n";

                    // Setup for figuring out which cache entries need deleted
                    $gp_dr = $ldt->getAncestor()->getGrandparent();
                    $records_needing_events[ $gp_dr->getId() ] = $gp_dr;

                    // Delete the linked_datatree entry
                    $ldt->setDeletedBy($user);
                    $ldt->setDeletedAt(new \DateTime());
                    $em->persist($ldt);

                    // NOTE: don't want to fire off an event right this moment, as it could cause
                    //  multiple updates for the same record
                }
            }

            // Flush once everything is deleted
            $em->flush();


            // ----------------------------------------
            // If the local datatype is using a sortfield that comes from the remote datatype,
            //  then need to wipe the local datatype's default sort ordering
            $query = $em->createQuery(
               'SELECT dtsf.id
                FROM ODRAdminBundle:DataTypeSpecialFields AS dtsf
                LEFT JOIN ODRAdminBundle:DataFields AS remote_df WITH dtsf.dataField = remote_df
                WHERE dtsf.dataType = :local_datatype_id AND dtsf.field_purpose = :field_purpose
                AND remote_df.dataType = :remote_datatype_id
                AND dtsf.deletedAt IS NULL AND remote_df.deletedAt IS NULL'
            )->setParameters(
                array(
                    'local_datatype_id' => $local_datatype_id,
                    'field_purpose' => DataTypeSpecialFields::SORT_FIELD,
                    'remote_datatype_id' => $remote_datatype_id,
                )
            );
            $dtsf_ids = $query->getArrayResult();

            if ( !empty($dtsf_ids) )
                $cache_service->delete('datatype_'.$local_datatype_id.'_record_order');


            // ----------------------------------------
            // Each of the records in $records_needing_events needs to be marked as updated and have
            //  their primary cache entries cleared
            try {
                foreach ($records_needing_events as $dr_id => $dr) {
                    $event = new DatarecordModifiedEvent($dr, $user);
                    $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                }
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Each of these records also needs to have their "associated_datarecords_for_<dr_id>"
            //  cache entry deleted so the view/edit pages can show the correct linked records
            $records_to_clear = array_keys($records_needing_events);

            try {
                $event = new DatarecordLinkStatusChangedEvent($records_to_clear, $descendant_datatype, $user);
                $dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            $return['d'] = array(
                'datatype_id' => $descendant_datatype->getId(),
                'datarecord_id' => $local_datarecord->getId()
            );
        }
        catch (\Exception $e) {
            $source = 0xdd047dcd;
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
     * Given a child datatype id and a datarecord, re-render and return the html for inline searches
     * on that child datatype.
     *
     * @param int $theme_element_id        The theme element this child/linked datatype is in
     * @param int $parent_datarecord_id    The parent datarecord of the child/linked datarecord
     *                                       that is getting reloaded
     * @param int $top_level_datarecord_id The datarecord currently being viewed in edit mode,
     *                                       required incase the user tries to reload B or C in the
     *                                       structure A => B => C => ...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function loadinlinelinkAction($theme_element_id, $parent_datarecord_id, $top_level_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            // This is only valid if the theme element has a child/linked datatype
            if ( $theme_element->getThemeDataType()->isEmpty() )
                throw new ODRBadRequestException();

            $theme = $theme_element->getTheme();
            $parent_datatype = $theme->getDataType();
            $top_level_datatype = $theme->getParentTheme()->getDataType();


            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_datarecord_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($parent_datarecord->getDataType()->getId() !== $parent_datatype->getId())
                throw new ODRBadRequestException();


            /** @var DataRecord $top_level_datarecord */
            $top_level_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($top_level_datarecord_id);
            if ($top_level_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($top_level_datarecord->getDataType()->getId() !== $top_level_datatype->getId())
                throw new ODRBadRequestException();


            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $theme_element->getThemeDataType()->first();
            $child_datatype = $theme_datatype->getDataType();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $return['d'] = array(
                'html' => $odr_render_service->loadInlineLinkChildtype(
                    $user,
                    $theme_element,
                    $parent_datarecord,
                    $top_level_datarecord,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xe36a63f7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
