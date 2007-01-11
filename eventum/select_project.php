<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003, 2004, 2005, 2006, 2007 MySQL AB                        |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: João Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//
// @(#) $Id: s.select_project.php 1.15 04/01/19 15:22:29-00:00 jpradomaia $
//
require_once("config.inc.php");
require_once(APP_INC_PATH . "class.template.php");
require_once(APP_INC_PATH . "class.project.php");
require_once(APP_INC_PATH . "class.auth.php");
require_once(APP_INC_PATH . "class.customer.php");
require_once(APP_INC_PATH . "db_access.php");

$tpl = new Template_API();
$tpl->setTemplate("select_project.tpl.html");

// check if cookies are enabled, first of all
if (!Auth::hasCookieSupport(APP_COOKIE)) {
    Auth::redirect(APP_RELATIVE_URL . "index.php?err=11");
}

if ((@$_GET["err"] == '') && (Auth::hasValidCookie(APP_COOKIE))) {
    $cookie = Auth::getCookieInfo(APP_PROJECT_COOKIE);
    if ($cookie["remember"]) {
        if (!empty($_GET["url"])) {
            Auth::redirect($_GET["url"]);
        } else {
            Auth::redirect(APP_RELATIVE_URL . "main.php");
        }
    }

    Language::setPreference();

    // check if the list of active projects consists of just
    // one project, and redirect the user to the main page of the
    // application on that case
    $assigned_projects = Project::getAssocList(Auth::getUserID());
    if (count($assigned_projects) == 1) {
        list($prj_id,) = each($assigned_projects);
        Auth::setCurrentProject($prj_id, 0);
        handleExpiredCustomer($prj_id);

        if (!empty($_GET["url"])) {
            Auth::redirect($_GET["url"]);
        } else {
            Auth::redirect(APP_RELATIVE_URL . "main.php");
        }
    } elseif ((!empty($_GET["url"])) && (
            (preg_match("/.*view\.php\?id=(\d*)/", $_GET["url"], $matches) > 0) ||
            (preg_match("/switch_prj_id=(\d*)/", $_GET["url"], $matches) > 0)
            )) {
        // check if url is directly linking to an issue, and if it is, don't prompt for project
        if (stristr($_GET["url"], 'view.php')) {
            $prj_id = Issue::getProjectID($matches[1]);
        } else {
            $prj_id = $matches[1];
        }
        if (!empty($assigned_projects[$prj_id])) {
            Auth::setCurrentProject($prj_id, 0);
            handleExpiredCustomer($prj_id);
            Auth::redirect($_GET["url"]);
        }
    }
}

if (@$_GET["err"] != '') {
    Auth::removeCookie(APP_PROJECT_COOKIE);
    $tpl->assign("err", $_GET["err"]);
}

if (@$_POST["cat"] == "select") {
    $usr_id = Auth::getUserID();
    $projects = Project::getAssocList($usr_id);
    if (!in_array($_POST["project"], array_keys($projects))) {
        // show error message
        $tpl->assign("err", 1);
    } else {
        // create cookie and redirect
        if (empty($_POST["remember"])) {
            $_POST["remember"] = 0;
        }
        Auth::setCurrentProject($_POST["project"], $_POST["remember"]);
        handleExpiredCustomer($_POST["project"]);

        if (!empty($_POST["url"])) {
            Auth::redirect($_POST["url"]);
        } else {
            Auth::redirect(APP_RELATIVE_URL . "main.php");
        }
    }
}

$tpl->displayTemplate();

function handleExpiredCustomer($prj_id)
{
    GLOBAL $tpl;

    if (Customer::hasCustomerIntegration($prj_id)) {
        // check if customer is expired
        $usr_id = Auth::getUserID();
        $contact_id = User::getCustomerContactID($usr_id);
        if ((!empty($contact_id)) && ($contact_id != -1)) {
            $status = Customer::getContractStatus($prj_id, User::getCustomerID($usr_id));
            $email = User::getEmailByContactID($contact_id);
            if ($status == 'expired') {
                Customer::sendExpirationNotice($prj_id, $contact_id, true);
                Auth::saveLoginAttempt($email, 'failure', 'expired contract');

                Auth::removeCookie(APP_PROJECT_COOKIE);

                $contact_id = User::getCustomerContactID($usr_id);
                $tpl->setTemplate("customer/" . Customer::getBackendImplementationName($prj_id) . "/customer_expired.tpl.html");
                $tpl->assign('customer', Customer::getContractDetails($prj_id, $contact_id, false));
                $tpl->displayTemplate();
                exit;
            } elseif ($status == 'in_grace_period') {
                Customer::sendExpirationNotice($prj_id, $contact_id);
                $tpl->setTemplate("customer/" . Customer::getBackendImplementationName($prj_id) . "/grace_period.tpl.html");
                $tpl->assign('customer', Customer::getContractDetails($prj_id, $contact_id, false));
                $tpl->assign('expiration_offset', Customer::getExpirationOffset($prj_id));
                $tpl->displayTemplate();
                exit;
            }
            // check with cnt_support to see if this contact is allowed in this support contract
            if (!Customer::isAllowedSupportContact($prj_id, $contact_id)) {
                Auth::saveLoginAttempt($email, 'failure', 'not allowed as technical contact');
                Auth::redirect(APP_RELATIVE_URL . "index.php?err=4&email=" . $email);
            }
        }
    }
}
