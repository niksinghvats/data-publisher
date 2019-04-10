<?php

/**
 * Open Data Repository Data Publisher
 * Datatype Export Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get xml or json versions of a single top-level datatype.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class DatatypeExportService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_service;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * DatatypeExportService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatypeInfoService $dti_service
     * @param PermissionsManagementService $pm_service
     * @param ThemeInfoService $theme_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatypeInfoService $datatype_info_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->theme_service = $theme_info_service;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Renders the specified datatype in the requested format according to the user's permissions.
     *
     * @param string $version           Which version of the export to render
     * @param integer $datatype_id      Which datatype to render
     * @param string $format            The format (json, xml, etc) to render the datatype in
     * @param boolean $using_metadata   Whether to display additional metadata (who created it, public date, revision, etc)
     * @param ODRUser $user             Which user requested this
     * @param string $baseurl           The current baseurl of this ODR installation, used for file/image links
     *
     * @return string
     */
    public function getData($version, $datatype_id, $format, $using_metadata, $user, $baseurl)
    {
        // All of these should already exist
        /** @var DataType $datatype */
        $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);

        $master_theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());

        $user_permissions = $this->pm_service->getUserPermissionsArray($user);


        // ----------------------------------------
        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $include_links = true;

        $datarecord_array = array();
        $datatype_array = $this->dti_service->getDatatypeArray($datatype->getId(), $include_links);
        $theme_array = $this->theme_service->getThemeArray($master_theme->getId());

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        // TODO - Something is wrong with permissions for tags.  May need to pull changes.
        // $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datatype_array[ $datatype->getId() ] = $this->dti_service->stackDatatypeArray($datatype_array, $datatype->getId());
        $stacked_theme_array[ $master_theme->getId() ] = $this->theme_service->stackThemeArray($theme_array, $master_theme->getId());


        // ----------------------------------------
        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:XMLExport:datatype_ajax.'.$format.'.twig';

        // Render the DataRecord
        $str = $this->templating->render(
            $template,
            array(
                'datatype_array' => $stacked_datatype_array,
                'theme_array' => $stacked_theme_array,

                'initial_datatype_id' => $datatype->getId(),
                'initial_theme_id' => $master_theme->getId(),

                'using_metadata' => $using_metadata,
                'baseurl' => $baseurl,
                'version' => $version,
            )
        );

        // If returning as json, reformat the data because twig can't correctly format this type of data
        if ($format == 'json')
            $str = self::reformatJson($str);

        return $str;
    }


    /**
     * Because of the recursive nature of ODR entities, any json generated by twig has a LOT of whitespace
     * and newlines...this function cleans up after twig by stripping as much of the extraneous whitespace as
     * possible.  It also ensures the final json string won't have the ",}" or ",]" character sequences outside of quotes.
     *
     * @param string $data
     *
     * @return string
     */
    private function reformatJson($data)
    {
        // Get rid of all whitespace characters that aren't inside double-quotes
        $trimmed_str = '';
        $in_quotes = false;

        for ($i = 0; $i < strlen($data); $i++) {
            if (!$in_quotes) {
                if ($data{$i} === "\"") {
                    // If not in quotes and a quote is encountered, transcribe it and switch modes
                    $trimmed_str .= $data{$i};
                    $in_quotes = true;
                }
                else if ($data{$i} === '}' && substr($trimmed_str, -1) === ',') {
                    // If not in quotes and would end up transcribing a closing brace immediately after a comma, replace the last comma with a closing brace instead
                    $trimmed_str = substr_replace($trimmed_str, '}', -1);
                }
                else if ($data{$i} === ']' && substr($trimmed_str, -1) === ',') {
                    // If not in quotes and would end up transcribing a closing bracket immediately after a comma, replace the last comma with a closing bracket instead
                    $trimmed_str = substr_replace($trimmed_str, ']', -1);
                }
                else if ($data{$i} === ',' && substr($trimmed_str, -1) === ',') {
                    // If not in quotes, then don't transcribe a comma when the previous character is also a comma
                }
                else if ($data{$i} !== ' ' && $data{$i} !== "\n") {
                    // If not in quotes and found a non-space character, transcribe it
                    $trimmed_str .= $data{$i};
                }
            }
            else {
                if ($data{$i} === "\"" && $data{$i-1} !== "\\")
                    $in_quotes = false;

                // If in quotes, always transcribe every character
                $trimmed_str .= $data{$i};
            }
        }

        // Also get rid of parts that signify no child/linked datatypes
        $trimmed_str = str_replace( array(',"child_databases":{}', ',"linked_databases":{}'), '', $trimmed_str );

        return $trimmed_str;
    }
}
