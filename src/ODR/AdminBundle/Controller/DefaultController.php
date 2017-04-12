<?php

/**
 * Open Data Repository Data Publisher
 * Default Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Default controller handles the loading of the base template
 * and AJAX handlers that the rest of the site uses.  It also
 * handles the creation of the information displayed on the site's
 * dashboard.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;


class DefaultController extends ODRCustomController
{

    /**
     * Triggers the loading of base.html.twig, and sets up session cookies.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $html = '';

        try {
            // Grab the current user
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            $datatype_permissions = array();
            if ($user !== 'anon.') {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
//            $user_permissions = parent::getUserPermissionsArray($em, $user->getId(), true);
                $datatype_permissions = $user_permissions['datatypes'];
            }

            // Render the base html for the page...$this->render() apparently creates a full Reponse object
            $html = $this->renderView(
                'ODRAdminBundle:Default:index.html.twig',
                array(
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                )
            );
        }
        catch (\Exception $e) {
            // This and ODROpenRepositorySearchBundle:Default:searchAction() are currently the only two controller actions that make Symfony handle the errors instead of AJAX popups
            throw new HttpException( 500, 'Error 0x1436562', $e );
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Loads the dashboard blurbs about the most populous datatypes on the site.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function dashboardAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Ensure user has correct set of permissions, since this is immediately called after login...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // No caching in dev environment
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Grab the cached graph data
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Only want to create dashboard html graphs for top-level datatypes...
            $datatree_array = parent::getDatatreeArray($em);
            $datatypes = parent::getTopLevelDatatypes();


            $dashboard_order = array();
            $dashboard_headers = array();
            $dashboard_graphs = array();
            foreach ($datatypes as $num => $datatype_id) {
                // Don't display datatype if user isn't allowed to view it
                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_view' ]) )
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dr_view' ]) )
                    $can_view_datarecord = true;

                $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype_id)));
                if ($bypass_cache || $datatype_data == false)
                    $datatype_data = parent::getDatatypeData($em, $datatree_array, $datatype_id, $bypass_cache);

                $public_date = $datatype_data[$datatype_id]['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s');
                if ($public_date == '2200-01-01 00:00:00' && !$can_view_datatype)
                    continue;


                // Attempt to load existing cache entry for this datatype's dashboard html
                $cache_entry = $redis_prefix.'.dashboard_'.$datatype_id;
                if (!$can_view_datarecord)
                    $cache_entry .= '_public_only';

                $data = parent::getRedisData(($redis->get($cache_entry)));
                if ($bypass_cache || $data == false) {
                    self::getDashboardHTML($em, $datatype_id);

                    // Cache entry should now exist, reload it
                    $data = parent::getRedisData(($redis->get($cache_entry)));
                }

                $total = $data['total'];
                $header = $data['header'];
                $graph = $data['graph'];

                $dashboard_order[$datatype_id] = $total;
                $dashboard_headers[$datatype_id] = $header;
                $dashboard_graphs[$datatype_id] = $graph;
            }

            // Sort by number of datarecords
            arsort($dashboard_order);

            $header_str = '';
            $graph_str = '';
            $count = 0;
            foreach ($dashboard_order as $datatype_id => $total) {
                // Only display the top 9 datatypes with the most datarecords
                $count++;
                if ($count > 9)
                    continue;

                $header_str .= $dashboard_headers[$datatype_id];
                $graph_str .= $dashboard_graphs[$datatype_id];
            }

            // Finally, render the main dashboard page
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Default:dashboard.html.twig',
                array(
                    'dashboard_headers' => $header_str,
                    'dashboard_graphs' => $graph_str,
                )
            );
            $return['d'] = array('html' => $html);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1883779 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Recalculates the dashboard blurb for a specified datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datatype_id             Which datatype is having its dashboard blurb rebuilt.
     */
    private function getDashboardHTML($em, $datatype_id)
    {
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        $datatype_name = $datatype->getShortName();

        // Temporarily disable the code that prevents the following query from returning deleted rows
        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT dr.id AS datarecord_id, dr.created AS created, dr.deletedAt AS deleted, dr.updated AS updated, drm.publicDate AS public_date
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dr.dataType = :datatype AND dr.provisioned = false
            AND drm.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datatype' => $datatype_id) );
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');    // Re-enable it


        // Build the array of date objects so datarecords created/deleted in the past 6 weeks can be counted
        $cutoff_dates = array();
        for ($i = 1; $i < 7; $i++) {
            $tmp_date = new \DateTime();
            $str = 'P'.($i*7).'D';

            $cutoff_dates[($i-1)] = $tmp_date->sub(new \DateInterval($str));
        }

        $total_datarecords = 0;
        $total_public_datarecords = 0;

        // Initialize the created/updated date arrays
        $tmp = array(
            'created' => array(),
            'updated' => array(),
        );
        for ($i = 0; $i < 6; $i++) {
            $tmp['created'][$i] = 0;
            $tmp['updated'][$i] = 0;
        }
        // Works since php arrays are assigned via copy
        $values = $tmp;
        $public_values = $tmp;


        // Classify each datarecord of this datatype
        foreach ($results as $num => $dr) {
            $create_date = $dr['created'];
            $delete_date = $dr['deleted'];
            if ($delete_date == '')
                $delete_date = null;
            $modify_date = $dr['updated'];
            $public_date = $dr['public_date']->format('Y-m-d H:i:s');

            // Determine whether the datarecord is public or not
            $is_public = true;
            if ($public_date == '2200-01-01 00:00:00')
                $is_public = false;

            // Don't count deleted datarecords towards the total number of datarecords for this datatype
            if ($delete_date == null) {
                $total_datarecords++;

                if ($is_public)
                    $total_public_datarecords++;
            }

            // If this datarecord was created in the past 6 weeks, store which week it was created in
            for ($i = 0; $i < 6; $i++) {
                if ($create_date > $cutoff_dates[$i]) {
                    $values['created'][$i]++;

                    if ($is_public)
                        $public_values['created'][$i]++;

                    break;
                }
            }

            // If this datarecord was deleted in the past 6 weeks, store which week it was deleted in
            if ($delete_date != null) {
                for ($i = 0; $i < 6; $i++) {
                    if ($delete_date > $cutoff_dates[$i]) {
                        $values['created'][$i]--;

                        if ($is_public)
                            $public_values['created'][$i]--;

                        break;
                    }
                }
            }

            // If this datarecord was deleted in the past 6 weeks, store which week it was deleted in
            if ($delete_date == null) {
                for ($i = 0; $i < 6; $i++) {
                    if ($modify_date > $cutoff_dates[$i]) {
                        $values['updated'][$i]++;

                        if ($is_public)
                            $public_values['updated'][$i]++;

                        break;
                    }
                }
            }
        }

//print $datatype_name."\n";
//print_r($values);

        // Calculate the total added/deleted since six weeks ago
        $total_created = 0;
        $total_public_created = 0;
        $total_updated = 0;
        $total_public_updated = 0;

        for ($i = 0; $i < 6; $i++) {
            $total_created += $values['created'][$i];
            $total_updated += $values['updated'][$i];

            $total_public_created += $public_values['created'][$i];
            $total_public_updated += $public_values['updated'][$i];
        }

        $value_str = $values['created'][5].':'.$values['updated'][5];
        $public_value_str = $public_values['created'][5].':'.$public_values['updated'][5];
        for ($i = 4; $i >= 0; $i--) {
            $value_str .= ','.$values['created'][$i].':'.$values['updated'][$i];
            $public_value_str .= ','.$public_values['created'][$i].':'.$public_values['updated'][$i];
        }

        $created_str = '';
        $public_created_str = '';
        if ( $total_created < 0 ) {
            $created_str = abs($total_created).' deleted';
            $public_created_str = abs($total_public_created).' deleted';
        }
        else {
            $created_str = $total_created.' created';
            $public_created_str = $total_public_created.' created';
        }

        $updated_str = $total_updated.' modified';
        $public_updated_str = $total_public_updated.' modified';


        // Render the actual html
        $templating = $this->get('templating');
        $header = $templating->render(
            'ODRAdminBundle:Default:dashboard_header.html.twig',
            array(
                'search_slug' => $datatype->getSearchSlug(),
                'datatype_id' => $datatype_id,
                'total_datarecords' => $total_datarecords,
                'datatype_name' => $datatype_name,
            )
        );
        $public_header = $templating->render(
            'ODRAdminBundle:Default:dashboard_header.html.twig',
            array(
                'search_slug' => $datatype->getSearchSlug(),
                'datatype_id' => $datatype_id,
                'total_datarecords' => $total_public_datarecords,
                'datatype_name' => $datatype_name,
            )
        );

        $graph = $templating->render(
            'ODRAdminBundle:Default:dashboard_graph.html.twig',
            array(
                'datatype_name' => $datatype_name,
                'created_str' => $created_str,
                'updated_str' => $updated_str,
                'value_str' => $value_str,
            )
        );
        $public_graph = $templating->render(
            'ODRAdminBundle:Default:dashboard_graph.html.twig',
            array(
                'datatype_name' => $datatype_name,
                'created_str' => $public_created_str,
                'updated_str' => $public_updated_str,
                'value_str' => $public_value_str,
            )
        );

        $data = array(
            'total' => $total_datarecords,
            'header' => $header,
            'graph' => $graph,
        );
        $public_data = array(
            'total' => $total_public_datarecords,
            'header' => $public_header,
            'graph' => $public_graph,
        );

        // Grab memcached stuff
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // TODO - Figure out how to set an lifetime using PREDIS
        // Store the dashboard data for all datarecords of this datatype
        $redis->set($redis_prefix.'.dashboard_'.$datatype_id, gzcompress(serialize($data)));
        $redis->expire($redis_prefix.'.dashboard_'.$datatype_id, 1*24*60*60); // Cache this dashboard entry for upwards of one day

        // Store the dashboard data for all public datarecords of this datatype
        $redis->set($redis_prefix.'.dashboard_'.$datatype_id.'_public_only', gzcompress(serialize($public_data)));
        $redis->expire($redis_prefix.'.dashboard_'.$datatype_id.'_public_only', 1*24*60*60); // Cache this dashboard entry for upwards of one day
    }

}
