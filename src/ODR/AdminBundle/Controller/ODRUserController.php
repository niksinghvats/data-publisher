<?php

/**
 * Open Data Repository Data Publisher
 * ODRUser Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The user controller handles setting user roles/permissions, and
 * completely replaces the default FoS functionality for creating,
 * editing, and deleting users.
 *
 * Password resetting and changing are handled by the ODR UserBundle,
 * which overrides the relevant sections of the FoS bundle.
 *
 * @see src\ODR\OpenRepository\UserBundle
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\UserFieldPermissions;
use ODR\AdminBundle\Entity\UserPermissions;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Forms
use ODR\AdminBundle\Form\ODRAdminChangePasswordForm;
use ODR\AdminBundle\Form\ODRUserProfileForm;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ODRUserController extends ODRCustomController
{

    /**
     * Renders a form to allow admins to create a new user
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createnewAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

            $admin_permission_count = 0;
            foreach ($admin_permissions as $datatype_id => $admin_permission) {
                if ( isset($admin_permission['admin']) && $admin_permission['admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------

            // Create the form that will be used
            $new_user = new ODRUser();
            $form = $this->createForm(ODRUserProfileForm::class, $new_user);

            // Render and return the form
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:create.html.twig',
                    array(
                        'profile_form' => $form->createView(),
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x882775132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Checks whether a given email address is already in use
     *
     * @param Request $request
     *
     * @return integer 0 if the email is not in use, otherwise returns the ID of the user that owns the email address
     */
    public function checknewAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

            $admin_permission_count = 0;
            foreach ($admin_permissions as $datatype_id => $admin_permission) {
                if ( isset($admin_permission['admin']) && $admin_permission['admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------

            // Attempt to find a user with this email address
            $post = $request->request->all();
            if ( !isset($post['email']) )
                throw new \Exception('Invalid Form');

            $email = $post['email'];
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserByEmail($email);

            // If found, return their user id
            if ($user !== null)
                $return['d'] = $user->getId();
            else
                $return['d'] = 0;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x822773532 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Uses the provided form to create a new user
     *
     * @param Request $request
     *
     * @return Response
     */
    public function savenewAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');
            $router = $this->get('router');

            $post = $request->request->all();
            if ( !isset($post['ODRUserProfileForm']) )
                throw new \Exception('Invalid Form');

            $post = $post['ODRUserProfileForm'];

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

            $admin_permission_count = 0;
            foreach ($admin_permissions as $datatype_id => $admin_permission) {
                if ( isset($admin_permission['admin']) && $admin_permission['admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------


            // Ensure a user with the specified email doesn't already exist...
            $email = $post['email'];
            /** @var ODRUser $user */
            $user = $user_manager->findUserByEmail($email);
            if ($user !== null) {
                // If user already exists, just return the url to their permissions page
                $url = $router->generate( 'odr_manage_user_permissions', array('user_id' => $user->getId()) );
                $return['d'] = array('url' => $url);
            }
            else {
                // Create a new user and bind the form to it
                $new_user = new ODRUser();
                $form = $this->createForm(ODRUserProfileForm::class, $new_user);

                $form->handleRequest($request);

                if ($form->isSubmitted()) {

                    // Password fields matching is handled by the Symfony Form 'repeated' field
                    // Password length and complexity is handled by the isPasswordValid() callback function in ODR\OpenRepository\UserBundle\Entity\User

                    // TODO - check for additional errors to throw?

//$form->addError( new FormError("don't save form...") );

                    // If no errors...
                    if ($form->isValid()) {
                        // Enable the user and give default roles
                        $new_user->setEnabled(true);
                        $new_user->addRole('ROLE_USER');

                        // Save changes to the user
                        $user_manager->updateUser($new_user);

                        // Generate and return the URL to modify the new user's permissions
                        $em->refresh($new_user);

                        $url = $router->generate( 'odr_manage_user_permissions', array('user_id' => $new_user->getId()) );
                        $return['d'] = array('url' => $url);

                        // The new user is going to need permissions eventually...now is as good a time as any to create them
                        $top_level_datatypes = parent::getTopLevelDatatypes();
                        foreach ($top_level_datatypes as $num => $datatype_id)
                            parent::permissionsExistence($em, $new_user->getId(), $admin_user->getId(), $datatype_id, null);

                    }
                    else {
                        // Form validation failed
                        $error_str = parent::ODR_getErrorMessages($form);
                        $return['d'] = array('html' => $error_str);
                    }
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = array('html' => 'Error 0x217735332 ' . $e->getMessage());
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns the profile editing HTML for a non-admin user
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function selfeditAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Create a new form to edit the user
            $form = $this->createForm(ODRUserProfileForm::class, $user, array('target_user_id' => $user->getId()));

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:profile.html.twig',
                    array(
                        'profile_form' => $form->createView(),
                        'current_user' => $user,
                        'target_user' => $user,
                        'self_edit' => 'true',
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678877532 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns the HTML for an admin user to modify profile information for any other user
     * 
     * @param integer $user_id The database id of the user to edit.
     * @param Request $request
     * 
     * @return Response
     */
    public function editAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null)
                return parent::deletedEntityError('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Bypass all this permissions sillyness if the user is a super admin, or doing this action to his own profile for some reason
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {

                // If user lacks super admin and admin roles, not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin->getId(), $request);
                $user_permissions = parent::getPermissionsArray($user_id, $request);

                $allow = false;
                foreach ($admin_permissions as $datatype_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) {
                        // allow this profile edit if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1 ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            // --------------------

            $self_edit = 'false';
            if ($admin->getId() == $user_id)
                $self_edit = 'true';

            // Create a new form to edit the user
            $form = $this->createForm(ODRUserProfileForm::class, $user, array('target_user_id' => $user->getId()));

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:profile.html.twig',
                    array(
                        'profile_form' => $form->createView(),
                        'current_user' => $admin,
                        'target_user' => $user,
                        'self_edit' => $self_edit,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678877532 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Wrapper function that allows a non-admin user to save changes to his own profile.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function selfsaveAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the current user
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Only allow this if the user is modifying their own profile
            $post = $request->request->all();
            if ( isset($post['ODRUserProfileForm']) && $user->getId() == $post['ODRUserProfileForm']['user_id'])
                $return = self::saveProfile($request);
            else
                throw new \Exception('Invalid form');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678773129 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Wrapper function that allows an admin user to save changes to any user's profile
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function saveAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Need to get the user id out of the form to check permissions...
            $post = $request->request->all();

            if ( !isset($post['ODRUserProfileForm']) )
                throw new \Exception('Invalid Form');
            $user_id = intval( $post['ODRUserProfileForm']['user_id'] );

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null)
                return parent::deletedEntityError('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Bypass all this permissions sillyness if the user is a super admin, or doing this action to his own profile for some reason
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {

                // If user lacks super admin and admin roles, not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    return parent::permissionDeniedError();
                
                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin->getId(), $request);
                $user_permissions = parent::getPermissionsArray($user_id, $request);

                $allow = false;
                foreach ($admin_permissions as $datatype_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) { 
                        // allow this profile edit if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1 ) { 
                            $allow = true;
                            break;
                        }   
                    }   
                }   

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            // --------------------

            // Save any changes to the profile
            $return = self::saveProfile($request);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678773129 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves modifications to user profiles to the database.
     * 
     * @param Request $request
     * 
     * @return array
     */
    private function saveProfile(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // Get required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $user_manager = $this->container->get('fos_user.user_manager');
        $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

//        $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

        // Grab which user is being modified
        $post = $request->request->all();
        if ( !isset($post['ODRUserProfileForm']) ) {
            $return['r'] = 1;
            $return['d'] = array('html' => 'Invalid Form');
            return $return;
        }

        $post = $post['ODRUserProfileForm'];
        $target_user_id = intval( $post['user_id'] );
        /** @var ODRUser $target_user */
        $target_user = $repo_user->find($target_user_id);
        if ($target_user == null)
            return parent::deletedEntityError('User');

        $email = $target_user->getEmail();

        // Bind the request to a form
        $form = $this->createForm(ODRUserProfileForm::class, $target_user, array('target_user_id' => $target_user->getId()));
        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            // TODO - check for additional non-password errors to throw?

            // If no errors...
            if ($form->isValid()) {
                // Save changes to the user
                $target_user->setEmail($email);     // as of right now, binding the form will clear the user's email/username because that field is disabled...set the email/username back to what it was originally
                $user_manager->updateUser($target_user);
            }
            else {
                // Form validation failed
                $error_str = parent::ODR_getErrorMessages($form);

                $return['r'] = 1;
                $return['d'] = array('html' => $error_str);
            }
        }

        return $return;
    }


    /**
     * Returns the HTML for an admin user to change another user's password
     * 
     * @param integer $user_id The database id of the user to edit.
     * @param Request $request
     * 
     * @return Response
     */
    public function changepasswordAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($target_user == null)
                return parent::deletedEntityError('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Bypass all this permissions sillyness if the user is a super admin, or doing this action to his own profile for some reason
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {

                // If user lacks super admin and admin roles, not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin->getId(), $request);
                $user_permissions = parent::getPermissionsArray($user_id, $request);

                $allow = false;
                foreach ($admin_permissions as $datatype_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) {
                        // allow this profile edit if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1 ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            // --------------------

            // Create a new form to edit the user
            $form = $this->createForm(ODRAdminChangePasswordForm::class, $target_user, array('target_user_id' => $target_user->getId()));

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:change_password.html.twig',
                    array(
                        'form' => $form->createView(),
                        'current_user' => $admin,
                        'target_user' => $target_user,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x677153132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes an admin makes to another user's password
     *
     * @param Request $request
     *
     * @return Response
     */
    public function savepasswordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');

            $post = $request->request->all();
            if ( !isset($post['ODRAdminChangePasswordForm']) )      // TODO - better way of grabbing this?
                throw new \Exception('Invalid Form');

            // Locate the target user
            $post = $post['ODRAdminChangePasswordForm'];
            $target_user_id = intval( $post['user_id'] );
            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($target_user_id);
            if ($target_user == null)
                return parent::deletedEntityError('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Bypass all this permissions sillyness if the user is a super admin, or doing this action to his own profile for some reason
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $target_user_id ) {

                // If user lacks super admin and admin roles, not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin->getId(), $request);
                $user_permissions = parent::getPermissionsArray($target_user_id, $request);

                $allow = false;
                foreach ($admin_permissions as $datatype_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) {
                        // allow this profile edit if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1 ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            // --------------------

            // Bind form to user
            $form = $this->createForm(ODRAdminChangePasswordForm::class, $target_user, array('target_user_id' => $target_user->getId()));
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                // Password fields matching is handled by the Symfony Form 'repeated' field
                // Password length and complexity is handled by the isPasswordValid() callback function in ODR\OpenRepository\UserBundle\Entity\User

                // TODO - check for additional errors to throw?

//$form->addError( new FormError('do not save...') );

                // If no errors...
                if ($form->isValid()) {
                    // Save changes to the user
                    $user_manager->updateUser($target_user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($form);

                    $return['r'] = 1;
                    $return['d'] = array('html' => $error_str);
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = array('html' => 'Error 0x213534325 ' . $e->getMessage());
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns a list of all registered users.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function listAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // User needs at least one "is_admin" permission to access this
            $user_permissions = parent::getPermissionsArray($admin_user->getId(), $request);
            $admin_permissions = array();
            foreach ($user_permissions as $datatype_id => $up) {
                if ( isset($up['admin']) && $up['admin'] == '1' ) {
                    $admin_permissions[ $datatype_id ] = $up;
                }
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && count($admin_permissions) == 0 )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser[] $user_list */
            $user_list = $user_manager->findUsers();

            // Reduce user list to those that possess a view permission to datatypes this user has admin permissions for, excluding super admins
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                $tmp = array();
                foreach ($user_list as $user) {
                    // Don't add super admins to this list
                    if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                        $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                        foreach ($user_permissions as $datatype_id => $up) {
                            if ( isset($up['view']) && $up['view'] == '1' && isset($admin_permissions[$datatype_id]) ) {
                                $tmp[] = $user;
                                break;
                            }
                        }
                    }
                }

                $user_list = $tmp;
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:list.html.twig',
                    array(
                        'users' => $user_list,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x68871752 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns HTML for modifying security roles (USER, DESIGNER, ADMIN) for a user.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function managerolesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();

            // Prevent the admin from modifying his own role (potentially removing his own admin role)
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:manage_roles.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x48871752 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Saves changes made to a user's role (user/editor/designer/admin)
     * 
     * @param integer $user_id The database id of the user being modified.
     * @param string $role     Which role to grant the user.
     * @param Request $request
     * 
     * @return Response
     */
    public function setroleAction($user_id, $role, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');

            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser[] $users */
            $users = $user_manager->findUsers();

            // Prevent the admin from modifying his own role (potentially removing his own admin role)
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Locate the user being changed
            foreach ($users as $user) {
                if (($user->getId() == $user_id) && ($user !== $admin_user)) {

                    // Grab all permissions for this user
                    /** @var UserPermissions[] $datatype_permissions */
                    $datatype_permissions = $repo_user_permissions->findBy( array('user' => $user) );
                    /** @var UserFieldPermissions[] $datafield_permissions */
                    $datafield_permissions = $repo_user_field_permissions->findBy( array('user' => $user) );

                    if ($role == 'user') {
                        $user->addRole('ROLE_USER');
                        $user->removeRole('ROLE_ADMIN');
                        $user->removeRole('ROLE_SUPER_ADMIN');
/*
                        // Firewall should block access, but delete all Design permissions for this user to be on the safe side
                        foreach ($user_permissions as $user_permission) {
                            $user_permission->setCanDesignType(0);
                            $em->persist($user_permission);
                        }
                        $em->flush();
*/
                    }
                    else if ($role == 'admin') {
                        $user->addRole('ROLE_USER');
                        $user->addRole('ROLE_ADMIN');
                        $user->removeRole('ROLE_SUPER_ADMIN');
                    }
                    else if ($role == 'sadmin') {
                        $user->addRole('ROLE_USER');
                        $user->addRole('ROLE_ADMIN');
                        $user->addRole('ROLE_SUPER_ADMIN');

                        $top_level_datatypes = parent::getTopLevelDatatypes();

                        // Enable all datatype permissions for this (now admin) user
                        $properties = array(
                            'can_view_type' => 1,
                            'can_add_record' => 1,
                            'can_edit_record' => 1,
                            'can_delete_record' => 1,
                            'can_design_type' => 1,
                        );

                        foreach ($datatype_permissions as $permission) {
                            // Determine whether this permission is for a top-level or child datatype
                            if ( in_array($permission->getDataType()->getId(), $top_level_datatypes) )
                                $properties['is_type_admin'] = 1;
                            else
                                $properties['is_type_admin'] = 0;

                            // Update this permission for this user
                            parent::ODR_copyUserPermission($em, $admin_user, $permission, $properties);
                        }

                        // Enable all datafield permissions for this (now admin) user
                        foreach ($datafield_permissions as $permission) {
                            $properties = array(
                                'can_view_field' => 1,
                                'can_edit_field' => 1
                            );
                            parent::ODR_copyUserFieldPermission($em, $user, $permission, $properties);
                        }

                        // Delete any cached permissions for this user
                        $redis = $this->container->get('snc_redis.default');;
                        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                        $redis->del($redis_prefix.'.user_'.$user_id.'_datatype_permissions');
                        $redis->del($redis_prefix.'.user_'.$user_id.'_datafield_permissions');
                    }

                    $user_manager->updateUser($user);
                    break;
                }
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x48781756 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns HTML listing all permissions for all datatypes for the selected user.
     * 
     * @param integer $user_id The database id of the user to modify.
     * @param Request $request
     * 
     * @return Response
     */
    public function managepermissionsAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

            $admin_permission_count = 0;
            foreach ($admin_permissions as $datatype_id => $admin_permission) {
                if ( isset($admin_permission['admin']) && $admin_permission['admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------


            // ----------------------------------------
            // Grab the user who will be having his permissions modified
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            // Don't allow modifying permissions of users with ROLE_SUPER_ADMIN
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                return parent::permissionDeniedError();

            // Don't allow users with just ROLE_USER to modify users that have ROLE_ADMIN
            if ( !$admin_user->hasRole('ROLE_ADMIN') && $user->hasRole('ROLE_ADMIN') )
                return parent::permissionDeniedError();


            // ----------------------------------------
            // Always bypass cache in dev mode
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;

            // Get the list of top-level datatype ids and the 
            $top_level_datatypes = parent::getTopLevelDatatypes();
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

            // Ensure the target user has permission entities for all datatypes in the database
            foreach ($top_level_datatypes as $num => $datatype_id)
                parent::permissionsExistence($em, $user->getId(), $admin_user->getId(), $datatype_id, null);

            // Load the target user's current set of permissions
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);


            // ----------------------------------------
            // Need to load and categorize all datatypes into top-level and child datatype groups
            $query = $em->createQuery(
               'SELECT dt, dtm
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                WHERE dt.deletedAt IS NULL AND dtm.deletedAt IS NULL');
            /** @var DataType[] $all_datatypes */
            $all_datatypes = $query->getArrayResult();

            $datatypes = array();
            $childtypes = array();
            foreach ($all_datatypes as $num => $datatype) {
                $dt_id = $datatype['id'];
                $datatype['dataTypeMeta'] = $datatype['dataTypeMeta'][0];

                if ( in_array($dt_id, $top_level_datatypes) ) {
                    // Store the datatype info
                    $datatypes[] = $datatype;
                }
                else {
                    // Determine which top-level datatype to store this under
                    $dt_id = parent::getGrandparentDatatypeId($datatree_array, $dt_id);

                    if ( !isset($childtypes[$dt_id]) )
                        $childtypes[$dt_id] = array();

                    $childtypes[$dt_id][] = $datatype;
                }
            }


            // ----------------------------------------
            // Render and return the HTML
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:manage_permissions.html.twig',
                    array(
                        'admin_user' => $admin_user,
                        'admin_permissions' => $admin_permissions,

                        'user' => $user,
                        'user_permissions' => $user_permissions,
                        'datatypes' => $datatypes,
                        'childtypes' => $childtypes
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8089132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns HTML allowing the admin to set a particular permission for a specific DataType for ALL users at once.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function quickpermissionsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();

            // Always bypass cache in dev mode
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;

            // ----------------------------------------
            // Get the list of top-level datatype ids
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $top_level_datatypes = parent::getTopLevelDatatypes();
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

            // Need to load all datatypes and categorize into top-level and children
            $query = $em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.deletedAt IS NULL');
            /** @var DataType[] $all_datatypes */
            $all_datatypes = $query->getArrayResult();

            $datatypes = array();
            $childtypes = array();
            foreach ($all_datatypes as $num => $datatype) {
                $dt_id = $datatype['id'];

                if ( in_array($dt_id, $top_level_datatypes) ) {
                    // Store the datatype info
                    $datatypes[] = $datatype;
                }
                else {
                    // Determine which top-level datatype to store this under
                    $dt_id = parent::getGrandparentDatatypeId($datatree_array, $dt_id);

                    if ( !isset($childtypes[$dt_id]) )
                        $childtypes[$dt_id] = array();

                    $childtypes[$dt_id][] = $datatype;
                }
            }


            // ----------------------------------------
            // Render and return the HTML
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:quick_permissions.html.twig',
                    array(
                        'datatypes' => $datatypes,
                        'childtypes' => $childtypes
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x876089132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates a UserPermission object in the database.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user                    The User that is getting their permissions modified.
     * @param ODRUser $admin_user              The User doing the modification to $user's permissions
     * @param DataType $datatype               The DataType that the User's permissions are being modified for.
     * @param string $permission               Which type of permission is being set.
     * @param integer $value                   0 if the permission is being revoked, 1 if the permission is being granted
     *
     */
    private function savePermission($em, $user, $admin_user, $datatype, $permission, $value)
    {
        // If the user is a super-admin, they always have all permissions...don't change anything
        if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
            // Don't allow admin permissions to be set on a non-top-level datatype...
            $top_level_datatypes = parent::getTopLevelDatatypes();
            if ( $permission == 'admin' && !in_array($datatype->getId(), $top_level_datatypes) )
                return;

            // Grab the user's permissions for this datatype
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            /** @var UserPermissions $user_permission */
            $user_permission = $repo_user_permissions->findOneBy( array('user' => $user, 'dataType' => $datatype) );
            if ( $user_permission == null )
                parent::permissionsExistence($em, $user->getId(), $user->getId(), $datatype->getId(), null);      // need to ensure a permission object exists before saving to it, obviously...

            // Ensure childtypes do not have the admin permission...shouldn't happen, but make doubly sure
            if ( !in_array($datatype->getId(), $top_level_datatypes) ) {
                $user_permission->setIsTypeAdmin('0');  // Intentionally using this instead of soft-deleting because it's an error if this childtype has this permission
                $em->persist($user_permission);
                $em->flush($user_permission);
                $em->refresh($user_permission);
            }

            // Don't change other permissions if user has admin permission for datatype
            if ( $permission !== 'admin' && $user_permission->getIsTypeAdmin() == '1' )
                return;

            // Modify their permissions based on the path
            $new_permissions = array();
            switch($permission) {
                case 'edit':
                    $new_permissions['can_edit_record'] = $value;
                    break;
                case 'add':
                    $new_permissions['can_add_record'] = $value;
                    break;
                case 'delete':
                    $new_permissions['can_delete_record'] = $value;
                    break;
                case 'view':
                    $new_permissions['can_view_type'] = $value;
                    break;
                case 'design':
                    $new_permissions['can_design_type'] = $value;
                    break;
                case 'admin':
                    $new_permissions['is_type_admin'] = $value;
                    break;
            }

            $reset_can_edit_datafield = false;

            if ($permission == 'edit') {
                // If the user had their edit permissions changed for this datatype, reset the edit permission on all datafields of this datatype
                $reset_can_edit_datafield = true;
            }
            else if ($permission == 'view' && $value == '0') {
                // If user no longer has view permissions for this datatype, disable all other permissions as well
                $new_permissions['can_edit_record'] = 0;
                $new_permissions['can_add_record'] = 0;
                $new_permissions['can_delete_record'] = 0;
                $new_permissions['can_design_type'] = 0;
                $new_permissions['is_type_admin'] = 0;

                $reset_can_edit_datafield = true;
            }
            else if ($permission == 'admin' && $value == '1') {
                // If user got admin permissions for this datatype, enable all other permissions as well
                $new_permissions['can_view_type'] = 1;
                $new_permissions['can_edit_record'] = 1;
                $new_permissions['can_add_record'] = 1;
                $new_permissions['can_delete_record'] = 1;
                $new_permissions['can_design_type'] = 1;
            }
            else {
                if ($value == '1') {
                    // If user gets some permission for the datatype, ensure they have have view permissions as well
                    if ($user_permission->getCanViewType() == '0')
                        $new_permissions['can_view_type'] = 1;      // TODO - test that this works again
                }
            }
            // Save changes to the datatype permission
            parent::ODR_copyUserPermission($em, $admin_user, $user_permission, $new_permissions);

            // If necessary to reset datafield edit permissions for this datatype...
            if ($reset_can_edit_datafield) {
                $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');
                /** @var UserFieldPermissions[] $datafield_permissions */
                $datafield_permissions = $repo_user_field_permissions->findBy( array('user' => $user->getId(), 'dataType' => $datatype->getId()) );

                foreach ($datafield_permissions as $df_permission) {
                    $properties = array();
                    if ($value == '1') {
                        // user was given edit permissions for this datatype...grant edit permissions for this datafield unless they're already restricted from viewing the datafield
                        if ($df_permission->getCanViewField() == '1')
                            $properties['can_edit_field'] = 1;
                    }
                    else {
                        // user had his edit permissions revoked for this datatype...revoke edit permissions for this datafield
                        $properties['can_edit_field'] = 0;
                    }

                    // Save changes to the datafield permission
                    parent::ODR_copyUserFieldPermission($em, $user, $df_permission, $properties);
                }
            }

            // Force a recache of the target user's permissions
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $redis->del($redis_prefix.'.user_'.$user->getId().'_datatype_permissions');
            if ($reset_can_edit_datafield)
                $redis->del($redis_prefix.'.user_'.$user->getId().'_datafield_permissions');
        }
    }


    /**
     * Updates a user's permissions for a given DataType.
     * 
     * @param integer $user_id     The database id of the User being modified.
     * @param integer $datatype_id The database id of the DataType these permissions are for.
     * @param integer $value       0 if permission is being revoked, 1 if permission being granted
     * @param string $permission   The permission being modified.
     * @param Request $request
     * 
     * @return Response
     */
    public function togglepermissionAction($user_id, $datatype_id, $value, $permission, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

            $admin_permission_count = 0;
            foreach ($admin_permissions as $dt_id => $admin_permission) {
                if ( isset($admin_permission['admin']) && $admin_permission['admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------


            // Grab the user from their id
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null)
                return parent::deletedEntityError('User');

            // Don't allow modifying permissions of users with ROLE_SUPER_ADMIN
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                return parent::permissionDeniedError();

            // Don't allow users with just ROLE_USER to modify users with ROLE_ADMIN
            if ( !$admin_user->hasRole('ROLE_ADMIN') && $user->hasRole('ROLE_ADMIN') )
                return parent::permissionDeniedError();


            // Grab the datatype that's being modified
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // Modify the user's permission for that datatype and any children it has
            self::savePermission($em, $user, $admin_user, $datatype, $permission, $value);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88817522 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Grants or revokes a specific permission of a DataType for all users.
     * 
     * @param integer $datatype_id The database id of the DataType being modified.
     * @param integer $value       0 if the permission is being revoked, 1 if the permission is being granted
     * @param string $permission   Which permission is being modified.
     * @param Request $request
     * 
     * @return Response
     */
    public function togglequickpermissionAction($datatype_id, $value, $permission, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser[] $users */
            $users = $user_manager->findUsers();

            // Grab the datatype that's being modified
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // Modify that permission for all the users
            foreach ($users as $user)
                self::savePermission($em, $user, $admin_user, $datatype, $permission, $value);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x883817922 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a User.
     * 
     * @param integer $user_id The database id of the user being deleted.
     * @param Request $request
     * 
     * @return Response
     */
    public function deleteAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser[] $users */
            $users = $user_manager->findUsers();

            // Prevent the admin from deleting himself
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Locate the user being changed
            foreach ($users as $user) {
                if (($user->getId() == $user_id) && ($user->getId() !== $admin_user->getId())) {
                    // This may not be the right way to do it...
                    $user->setEnabled(0);
                    $user_manager->updateUser($user);
                    break;
                }
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:list.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88817522 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Undeletes a User.
     * 
     * @param integer $user_id The database id of the user being reinstated.
     * @param Request $request
     * 
     * @return Response
     */
    public function undeleteAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser[] $users */
            $users = $user_manager->findUsers();

            // Needed for the twig file
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Locate the user being changed
            foreach ($users as $user) {
                if ($user->getId() == $user_id) {
                    // This may not be the right way to do it...
                    $user->setEnabled(1);
                    $user_manager->updateUser($user);
                    break;
                }
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:list.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88817523 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Changes the number of Datarecords displayed per ShortResults page...TextResults handles its own version
     *
     * @param integer $length  How many Datarecords to display on a page.
     * @param Request $request
     *
     * @return Response
     */
    public function pagelengthAction($length, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $session = $request->getSession();

            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            if ($odr_tab_id !== '') {
                // Store the change to this tab's page_length in the session
                $page_length = intval($length);
                $stored_tab_data = array();
                if ( $session->has('stored_tab_data') )
                    $stored_tab_data = $session->get('stored_tab_data');

                if ( !isset($stored_tab_data[$odr_tab_id]) )
                    $stored_tab_data[$odr_tab_id] = array();

                $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                $session->set('stored_tab_data', $stored_tab_data);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x648812732 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns an interface for datafield-level permissions.
     *
     * @param integer $user_id     The database id of the User having their permissions modified
     * @param integer $datatype_id The database id of the DataType being modified
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldpermissionsAction($user_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Grab the user from their id
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {

                // If target user is super admin, not allowed to do this
                if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($admin_permissions[$datatype_id]) || !isset($admin_permissions[$datatype_id]['admin']) )
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            // Grab the datatype that's being modified
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new \Exception("Not allowed to render Datafield Permissions UI on a non-master Theme");


            // Get the html for assigning datafield permissions
            $return['d'] = array(
                'html' => self::GetDisplayData($user, $datatype_id, 'default', $datatype_id, $request)
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x648413732 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a child DataType div in the design.
     *
     * @param integer $user_id             The user having his permissions modified
     * @param integer $source_datatype_id  The database id of the top-level Datatype
     * @param integer $childtype_id        The database id of the child DataType that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldpermissionschildtypeAction($user_id, $source_datatype_id, $childtype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var DataType $childtype */
            $childtype = $em->getRepository('ODRAdminBundle:DataType')->find($childtype_id);
            if ($childtype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $childtype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new \Exception("Not allowed to re-render a Child Datatype that doesn't belong to the master Theme");


            // --------------------
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {

                // If target user is super admin, not allowed to do this
                if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($admin_permissions[$source_datatype_id]) || !isset($admin_permissions[$source_datatype_id]['admin']) )
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            $return['d'] = array(
                'datatype_id' => $childtype_id,
                'html' => self::GetDisplayData($user, $source_datatype_id, 'child_datatype', $childtype_id, $request),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x79163252' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the design.
     *
     * @param integer $user_id             The user having his permissions modified
     * @param integer $source_datatype_id  The database id of the top-level datatype being rendered?
     * @param integer $theme_element_id    The database id of the ThemeElement that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldpermissionsthemeelementAction($user_id, $source_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new \Exception('not needed right this moment...');

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new \Exception("Not allowed to re-render a ThemeElement that doesn't belong to the master Theme");
/*
            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');
*/

            // --------------------
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {

                // If target user is super admin, not allowed to do this
                if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($admin_permissions[$source_datatype_id]) || !isset($admin_permissions[$source_datatype_id]['admin']) )
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($user, $source_datatype_id, 'theme_element', $theme_element_id, $request),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x792133260' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders the HTML required to display/reload a portion of the Datafield Permissions changer UI
     *
     * @param ODRUser $user                The user having their permissions modified
     * @param integer $source_datatype_id  The top-level datatype that $user is having permissions modified for
     * @param string $template_name        One of 'default', 'child_datatype', 'theme_element'
     * @param integer $target_id           If $template_name == 'default', then $target_id should be a top-level datatype id
     *                                     If $template_name == 'child_datatype', then $target_id should be a child/linked datatype id
     *                                     If $template_name == 'theme_element', then $target_id should be a theme_element id
     * @param Request $request
     *
     * @return string
     */
    private function GetDisplayData($user, $source_datatype_id, $template_name, $target_id, $request)
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Grab datatype-level permissions for the specified user
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        // Grab the datafield-level permissions for the specified user
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);


        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Going to need this a lot...
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);


        // ----------------------------------------
        // Load required objects based on parameters
        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataType|null $child_datatype */
        $child_datatype = null;
        /** @var ThemeElement|null $theme_element */
        $theme_element = null;


        // Don't need to check whether these entities are deleted or not
        if ($template_name == 'default') {
            $datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        }
        else if ($template_name == 'child_datatype') {
            $child_datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // Need to determine the top-level datatype to be able to load all necessary data for rendering this child datatype
            if ( isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) && $datatree_array['descendant_of'][ $child_datatype->getId() ] !== '' ) {
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
            else if ( !isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) || $datatree_array['descendant_of'][ $child_datatype->getId() ] == '' ) {
                // Was actually a re-render request for a top-level datatype...re-rendering should still work properly if various flags are set right
                $datatype = $child_datatype;
            }
        }
        else if ($template_name == 'theme_element') {
/*
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($target_id);
            $theme = $theme_element->getTheme();

            // This could be a theme element from a child datatype...make sure objects get set properly if it is
            $datatype = $theme->getDataType();
            if ( isset($datatree_array['descendant_of'][ $datatype->getId() ]) && $datatree_array['descendant_of'][ $datatype->getId() ] !== '' ) {
                $child_datatype = $theme->getDataType();
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
*/
        }


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = false;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();


        // ----------------------------------------
        // No need to filter by user permissions...the only people who can currently access this functionality already have permissions to view/edit everything


        // ----------------------------------------
        // Render the required version of the page
        $templating = $this->get('templating');

        $html = '';
        if ($template_name == 'default') {
            $html = $templating->render(
                'ODRAdminBundle:FieldPermissions:fieldpermissions_ajax.html.twig',
                array(
                    'user' => $user,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'datatype_array' => $datatype_array,
                    'initial_datatype_id' => $source_datatype_id,
                    'theme_id' => $theme->getId(),
                )
            );
        }
        else if ($template_name == 'child_datatype') {
            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $child_datatype->getId();
            $is_top_level = 1;
            if ($child_datatype->getId() !== $datatype->getId())
                $is_top_level = 0;


            // TODO - not really preventing this earlier i think...
            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId()) {
                $is_top_level = 0;
                $is_link = 1;
            }

            $html = $templating->render(
                'ODRAdminBundle:FieldPermissions:fieldpermissions_childtype.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'user' => $user,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'is_top_level' => $is_top_level,
                )
            );
        }
        else if ($template_name == 'theme_element') {
/*
            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $datatype->getId();
            $is_top_level = 1;
            if ($child_datatype !== null) {
                $target_datatype_id = $child_datatype->getId();
                $is_top_level = 0;
            }

            // TODO - not really preventing this earlier i think...
            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId())
                $is_link = 1;

            // design_fieldarea.html.twig attempts to render all theme_elements in the given theme...
            // Since this is a request to only re-render one of them, unset all theme_elements in the theme other than the one the user wants to re-render
            foreach ($datatype_array[ $datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( $te['id'] != $target_id )
                    unset( $datatype_array[ $datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'][$te_num] );
            }

//            print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

            $html = $templating->render(
                'ODRAdminBundle:FieldPermissions:fieldpermissions_fieldarea.html.twig',
                array(
                    'user' => $user,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'is_top_level' => $is_top_level,
                )
            );
*/
        }

        return $html;
    }


    /**
     * Saves a datafield permission change for a given user.
     *
     * @param integer $user_id      The database id of the User being modified.
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param integer $permission   '2' => can view/can edit, '1' => can view/no edit, '0' => no view/no edit
     * @param Request $request
     *
     * @return Response
     */
    public function savedatafieldpermissionAction($user_id, $datafield_id, $permission, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Grab the user from their id
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            // Grab the datafield that's being modified
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();

            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {

                // If target user is super admin, not allowed to do this
                if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request);
                $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                $allow = false;
                if ( isset($admin_permissions[$datatype_id]) && isset($admin_permissions[$datatype_id]['admin']) && $admin_permissions[$datatype_id]['admin'] == 1 ) {
                    if ( isset($admin_permissions[$datatype_id]) && isset($admin_permissions[$datatype_id]['view']) && $admin_permissions[$datatype_id]['view'] == 1 ) {
                        $allow = true;
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            // Grab the UserFieldPermission object that's being modified
            /** @var UserFieldPermissions $user_field_permission */
            $user_field_permission = $em->getRepository('ODRAdminBundle:UserFieldPermissions')->findOneBy( array('user' => $user->getId(), 'dataField' => $datafield->getId()) );

            $properties = array();
            if ($permission == 2) {
                $properties['can_view_field'] = 1;
                $properties['can_edit_field'] = 1;
            }
            else if ($permission == 1) {
                $properties['can_view_field'] = 1;
                $properties['can_edit_field'] = 0;
            }
            else if ($permission == 0) {
                $properties['can_view_field'] = 0;
                $properties['can_edit_field'] = 0;
            }
            parent::ODR_copyUserFieldPermission($em, $user, $user_field_permission, $properties);

            // Force a recache of the target user's permissions
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $redis->del($redis_prefix.'.user_'.$user->getId().'_datafield_permissions');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x648233742 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
