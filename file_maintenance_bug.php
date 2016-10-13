<?php
// Notify email
function notify_mail($rhn_links) {
    $receipient = "ax-tech@asianux.com";
    foreach ($rhn_links as $rhn_link) {
        $errata_lists.= "<p>" . $rhn_link . "</p>";
    }
    $message = <<<EOF
From: Asianux Continuous Integration <axbld@asianux.com>
To: ax-tech@asianux.com
Subject: Red Hat new packages 
Content-Type: text/html

<!DOCTYPE html>
<body>
<p> Hello everyone, </p>
<p> There are new packages coming from Red Hat Network. Please consider if they are appropriate for Asianux.</p>
<p> If so, please check if any modification is needed then set appropriate "Test type" attribute (NT/RAT) on Bugzilla, update pkglist in comps then run the Jenkins job <a href="http://jenkins.asianux.com/job/maintenance_build/">maintenance_build</a> again.</p>
<p> List of errata with new packages:</p>
$errata_lists
</body>
</html>
EOF;
    file_put_contents("/tmp/message", $message);
    shell_exec("/usr/sbin/sendmail ax-tech@asianux.com < /tmp/message");
}
// Copy source to temp pool
function copy_SRPMS($product, $affected_products, $SRPMSs) {
    switch ($product) {
    case "'Asianux 4.0'":
        if (strpos($affected_products, "Software Collections 1 for RHEL 6")) {
            $src_link = "http://ftp.redhat.com/pub/redhat/linux/enterprise/6Server/en/RHSCL/SRPMS/";
        }
        else {
            $src_link = "http://ftp.redhat.com/pub/redhat/linux/enterprise/6Server/en/os/SRPMS/";
        }
        foreach ($SRPMSs as $SRPMS) {
            shell_exec("curl $src_link$SRPMS > /project/pool/Hiranya/SRPMS.el6/$SRPMS");
            echo "Copied ".$SRPMS." to /project/pool/Hiranya/SRPMS.el6/\n";
        }
        break;
    case "'Asianux 7.0'":
        foreach ($SRPMSs as $SRPMS) {
            $package_name = get_package_name($SRPMS);
            exec("ssh ax7-amd64-builder /project/bin/generate-srpm.sh -p $package_name", $ret, $output);
            if (strpos($ret[0], "src_URL") !== FALSE) {
                echo "Generated and copied ".$SRPMS." to /project/pool/Lotus/SRPMS.el7/\n";
            }
            else {
                echo "FAILED to generate ". $SRPMS."\n";
            }
        }  
        break;
    }
    return TRUE;
}
//Generate build order
function gen_build_order($bug_id, $SRPMSs) {
    $file = fopen("/project/maintenanace/order/$bug_id","a");
    for ($i = 0; $i < count($SRPMSs); $i++) {
        fwrite($file, $SRPMSs[$i] . "\n");
    }
    fclose($file);
}
// Try to produce Asianux description from Red Hat's
function produce_AX_desc($description, $product) {
    $str = $description;
    switch ($product) {
    case "'Asianux 7.0'":
        $str = str_replace("Red Hat Enterprise Linux 7", "Asianux Server 7", $description);
        break;
    case "'Asianux 4.0'":
        $str = str_replace("Red Hat Enterprise Linux 6", "Asianux Server 4", $description);
        break;
    }
    $str = str_replace("Red Hat Software Collections", "Asianux Software Collections", $description);
    $str = str_replace("Red Hat Enterprise Linux", "Asianux Server", $description);
    $str = str_replace("Red Hat Enterprise Linux Technical Notes", "Asianux Release Notes",  $description);
    $str = str_replace("Red Hat Enterprise", "Asianux", $description);
    return $str;
}
// Check whether erratum is outdated
function check_outdated_errata($xpath, $product, $total_pkgs) {
    $main_affected_product = get_primary_affected_product($xpath, $product);
    $i = 0;
    $j = 0;
    while ($i <= $total_pkgs) {
        $query = "//div[@id='content']/table[2]/tr[td//text()[contains(., '".$main_affected_product."')]]/following-sibling::tr[3 + $i]/td[1]";
        $entries = $xpath->query($query);
        $string = $entries->item(0)->textContent;
        if (strpos($string, "File outdated by") !== FALSE) {
            $j++;
        }
        $i++;
    }
    if ($j === $total_pkgs) {
        return TRUE;
    }
    return FALSE;
}
// Get test type
function get_test_type($package_names, $product) {
    $test_type = "NT";
    for ($i = 0; $i < count($package_names); $i++) {
        $package_name = $package_names[$i];
        if (strpos($package_name, "kernel") !== FALSE) {
            $test_type = "RAT";
            return $test_type;
        }
        exec("/project/bin/check_package_modified.sh $package_name $product", $temp, $result);
        if (stripos($temp[0], "asianux") !== FALSE) {
            
            $test_type = "RAT";
            return $test_type;
        }
        if (strpos($temp[0], "Package not found") !== FALSE ) {
            $test_type = "---";
            return $test_type;
        }
    }
    return $test_type;
}
// Get list of modified packages
function get_modified_pkgs($pkg_names, $product) {
    $pkgs = array();
    foreach ($pkg_names as $pkg_name) {
        exec("/project/bin/check_package_modified.sh $pkg_name $product", $temp, $result);
        if (stripos($temp[0], "asianux") !== FALSE) {
            array_push($pkgs, $pkg_name);
        }
        if (stripos($temp[0], "Package not found") !== FALSE) {
            array_push($pkgs, $pkg_name." (new)"); 
        }
    }
    return $pkgs;
}
// These values will be used by script make_errata_page
function generate_errata_common($bug_id, $bug_type, $bug_severity, $errata_description) {
    $file_errata_description = "/project/maintenance/errata/template/$bug_id/errata-description";
    $file_errata_type = "/project/maintenance/errata/template/$bug_id/errata-type";
    shell_exec("mkdir -p /project/maintenance/errata/template/$bug_id && touch $file_errata_description && touch $file_errata_type");
    if (strpos($bug_type, "security") !== FALSE) {
        $errata_type = "Security Update";
        if (strpos($bug_severity, "2.critical") !== FALSE || strpos($bug_severity, "3.major") !== FALSE) {
            $errata_severity = "High";
        }
        elseif (strpos($bug_severity, "4.normal") !== FALSE) {
            $errata_severity = "Moderate";
        }
        elseif (strpos($bug_severity, "5.minor") !== FALSE) {
            $errata_severity = "Low";
        }
        else {
            $errata_severity = "";
        }
    }
    elseif (strpos($bug_type, "fixed") !== FALSE) {
        $errata_type = "Bug Fix";
        $errata_severity = "";
    }
    else {
        $errata_type = "Enhancement";
        $errata_severity = "";
    }
    $errata_description = substr($errata_description, strpos($errata_description, "Description: ") + strlen("Description: "));
    $errata_description = trim($errata_description);
    $ret = file_put_contents($file_errata_description, $errata_description);
    $errata_type = "field_type[und]=".$errata_type;
    $errata_severity = "field_severity[und]=".$errata_severity;
    $content = (strpos($errata_type, "Security Update") !== FALSE ? $errata_type."&".$errata_severity."&" : $errata_type."&");
    $ret1 = file_put_contents($file_errata_type, $content);
    if ($ret == FALSE or $ret1 == FALSE) {
        return FALSE;
    }
    return TRUE;
}
// Get the id of filed bug
function get_bug_id($output) {
    $strtemp = "* Info: Bug ";
    $output1 = substr($output, strpos($output, $strtemp) + strlen($strtemp));
    $bug_id = substr($output1, 0, strpos($output1, " "));
    return $bug_id;
}
// Check an erratum is already filed
function is_erratum_filed($rhn_erratum) {
    $filed_errata = file("/project/maintenance/errata/list-filed-errata.txt");
    foreach($filed_errata as $filed_erratum) {
        $filed_erratum = trim($filed_erratum);
        if (strcasecmp($filed_erratum, $rhn_erratum) === 0) {
            return TRUE;
        }
    }
    return FALSE;
}
// Update list of filed errata
function update_filed_errata($list_errata_link) {
    $file = "/project/maintenance/errata/list-filed-errata.txt";
    $current = file_get_contents($file);
    for ($i = 0; $i < count($list_errata_link); $i++) {
        $current .= $list_errata_link[$i]."\n";
    }
    $ret = file_put_contents($file, $current);
    if ($ret === FALSE) {
        return FALSE;
    }
    return TRUE;
}
// Load Red Hat erratum to xpath document
function load_errata_xpath($rhn_erratum_link) {
    $doc = new DOMDocument();
    error_reporting(E_ERROR | E_PARSE);
    $doc->preserveWhiteSpace = false;
    $doc->strictErrorChecking = false;
    $doc->recover = true;
    $doc->loadHTMLFile($rhn_erratum_link);
    $xpath = new DOMXPath($doc);
    return $xpath;
}
// Get inner html from DOM
function DOMinnerHTML($element) {
    $innerHTML = "";
    $children = $element->childNodes;
    foreach ($children as $child) {
        $tmp_dom = new DOMDocument();
        $tmp_dom->appendChild($tmp_dom->importNode($child, true));
        $innerHTML.=trim($tmp_dom->saveHTML());
    }
    return $innerHTML;
}
// Select erratum from RHN errata page
function select_erratum_to_file($errata_page, $current_date) {
    $xpath = load_errata_xpath($errata_page);
    $query = "//div[@id='content']/script";
    $entries = $xpath->query($query);
    $str1 = "";
    foreach ($entries as $entry) {
        $str1 .= DOMinnerHTML($entry);
    }
    $array = explode("],[", $str1);
    $array_temp = array();
    for ($i = 0; $i < count($array); $i++) {
        if (strpos($array[$i], $current_date) !== FALSE) {
            array_push($array_temp, $array[$i]);
            if (strpos($array[$i + 1], $current_date) === FALSE) {
                break;
            }
        }
    }
    $list_errata_link = array();
    for ($i = 0; $i < count($array_temp); $i++) {
        if (strlen($array_temp[$i]) > 0) {
            $array_temp2 = explode("','", $array_temp[$i]);
            array_push($list_errata_link, "https://rhn.redhat.com/errata/".str_replace(":", "-", $array_temp2[2]).".html");
        }
    }
    return $list_errata_link;
}
// Check comps AXS4 whether to file bug
function check_comps($package_name, $product_number) {
    $pkglist_files = array(
        "comps/Asianux/$product_number/pkglist-released-SRPMS",
        "comps/Asianux/$product_number/pkglist-unreleased-SRPMS",
        "comps/Asianux/$product_number/pkglist-SCL-SRPMS",
        "comps/Asianux/$product_number/pkglist-SCLmeta-SRPMS"
    ); 
    foreach ($pkglist_files as $pkglist_file) {
        $package_names = file($pkglist_file);
        foreach($package_names as $name) {
            $name = trim($name);
            if (strcasecmp($name, $package_name) === 0) {
                return TRUE;
            }
        } 
    }
    return FALSE;
} 

// Get a primary affected product, this help avoid duplicate bugs
function get_primary_affected_product($xpath, $product) {
    $main_affected_product = "";
    $affected_products = array();
    $query = "//div[@id='container']/div[@id='content']/table[@class='details']/tr[6]/td";
    $entries = $xpath->query($query);
    $products = $entries->item(0)->textContent;
    $rh_products = array(
        "Red Hat Enterprise Linux Server (v.",
        "Red Hat Enterprise Linux High Availability (v.",
        "Red Hat Enterprise Linux Server FasTrack (v.",
        "Red Hat Enterprise Linux Resilient Storage (v.",
        "Red Hat Enterprise Linux Load Balancer (v."
    );
    foreach ($rh_products as $rh_product) {
        if (strpos($products, $rh_product) !== FALSE) {
            $main_affected_product = $rh_product;
            break;
        }
    }
    if (strpos($product, "Asianux 4.0") !== FALSE) {
        if (strpos($products, "Red Hat Software Collections 1 for RHEL 6") !== FALSE) {
            $main_affected_product = "Red Hat Software Collections 1 for RHEL 6";
        }
        else {
            $main_affected_product .= " 6)";
        }
    }
    else {
        if (strpos($products, "Red Hat Software Collections 1 for RHEL 7") !== FALSE) {
            $main_affected_product = "Red Hat Software Collections 1 for RHEL 7";
        }
        else {
            $main_affected_product .= " 7)";
        }
    }
    return $main_affected_product;
}
// Check a string not contains any elements in array
function string_not_contains_any_array_elements($string, array $array) {
    foreach($array as $item) {
        if (stripos($string, $item) !== FALSE) {
            return FALSE;
        }
    }
    return TRUE;
}
// Generate bug title from RHN erratum title
function generate_bug_title($bug_type, $xpath) {
    $query = "//div[@id='content']/h1";
    $entries = $xpath->query($query);
    $errata_title = $entries->item(0)->textContent;
    $errata_title = trim($errata_title);
    $pos1 = strpos($errata_title, ':');
    if (strpos($errata_title, "new packages") !== FALSE) {
        $errata_title = substr($errata_title, $pos1 + 2);
        $bug_title = "[new packages] ".$errata_title;
        return $bug_title;
    }
    if (strpos($bug_type, "security") !== FALSE) {
        $errata_title = substr($errata_title, $pos1 + 2);
        $priority = get_priority($xpath);
        $errata_title = "[security - ".strtolower(substr($priority, 2))."] ".$errata_title;
    }
    $bug_title = $errata_title;
    return $bug_title;
}
// Get CVE description from CVE id
function get_cve_description($cve_id) {
    $cve_prefix_link = "http://cve.mitre.org/cgi-bin/cvename.cgi?name=";
    $CVElink = $cve_prefix_link.$cve_id;
    $doc = new DOMDocument;
    error_reporting(E_ERROR | E_PARSE);
    $doc->preserveWhiteSpace = false;
    $doc->strictErrorChecking = false;
    $doc->recover = true;
    $doc->loadHTMLFile($CVElink);
    $xpath = new DOMXPath($doc);
    $query = "//div[@id='GeneratedTable']";
    $entries = $xpath->query($query);
    $mystring = $entries->item(0)->textContent;
    $start = "Description";
    $end = "References";
    $string = " ".$mystring;
    $ini = strpos($string,$start);
    if ($ini == 0)
        return "";
    $ini += strlen($start);
    $len = strpos($string,$end,$ini) - $ini;
    $strResult = substr($string,$ini,$len);
    $description = $strResult;
    return $description; 
}
// Get CVE from xpath
function get_cve($xpath) {
    $cve_prefix_link = "http://cve.mitre.org/cgi-bin/cvename.cgi?name=";
    $query = "//div[@id='content']/table[@class='details']/tr[7]/td";
    $entries = $xpath->query($query);
    $string = $entries->item(0)->textContent;
    $string_ret = "";
    if (substr_count($string, "CVE") == 1) {
        $cve = trim($string);
        $string_ret = $cve."\n".trim(get_cve_description($cve))."\n";
    }
    else {
        $cves = explode("CVE", $string);
        for ($i = 1; $i < count($cves); $i++) {
            $cves[$i] = "CVE".$cves[$i];
            $str_temp = trim(get_cve_description($cves[$i]));
            $string_ret .= $cves[$i]."\n".$str_temp."\n";
        }
    }
    return $string_ret;
}
// Get erratum description
function get_description($xpath, $errata_title) {
    $ret_string = "";
    $query = "//div[@id='container']/div[@id='content']/div[@class='page-summary']/p[2]";
    $entries = $xpath->query($query);
    foreach ($entries as $entry) {
        $str1 .= DOMinnerHTML($entry);
    }
    if (strpos($errata_title, "[new packages]") !== FALSE) {
        $array_temp = explode("<br><br>", $str1);
        for ($i = 0; $i < count($array_temp); $i++) {
            $pos = stripos($array_temp[$i], "(BZ");
            if ($pos !== FALSE) {
                $array_temp[$i] = substr($array_temp[$i], 0, $pos);
            }
        }
        $str_temp = implode("\n", $array_temp);
        $ret_string = str_replace("<br>", " ", $str_temp);
        return $ret_string;
    }
    $array_temp = explode("<br><br>", $str1);
    $array_temp2 = array();
    # To be updated
    $list_string1 = array("CVE-", "access.redhat", "security issue", "Refer to RH", "Note:", "Security Fix(es):");
    for ($i = 0; $i < count($array_temp); $i++) {
        if (strpos($array_temp[$i], ":") !== FALSE or strpos($array_temp[$i], "*") !== FALSE) {
            if (string_not_contains_any_array_elements($array_temp[$i], $list_string1) !== FALSE) {
                array_push($array_temp2, $array_temp[$i]);
            }
        }
    }
    for ($i = 0; $i < count($array_temp2); $i++) {
        if (stripos($array_temp2[$i], "bug") !== FALSE and strpos($array_temp2[$i], "*") === FALSE) {
            $array_temp2[$i] = "Fixed bugs:\n";
        }
        if (stripos($array_temp2[$i], "enhancement") !== FALSE and strpos($array_temp2[$i], "*") === FALSE) {
            $array_temp2[$i] = "Enhancements:\n";
        }
        $pos = stripos($array_temp2[$i], "(BZ");
        if ($pos !== FALSE) {
            $array_temp2[$i] = substr($array_temp2[$i], 0, $pos);
        }
    }
    $str_temp = implode("\n", $array_temp2);
    for ($i = 0; $i < count($array_temp); $i++) {
        if (stripos($array_temp[$i], "This update also") !== FALSE and strpos($array_temp[$i], ":") === FALSE) {
            $pos = strpos($array_temp[$i], ".");
            $str_temp .= "\n".substr($array_temp[$i], 0, $pos + 1);
        }
        # To be updated
        $list_string2 = array("all users of", "users of", "advised to", "upgrade to");
        if (stripos($array_temp[$i], "These updated")  !== FALSE and string_not_contains_any_array_elements($array_temp[$i], $list_string2) !== FALSE) {
            $pos = strpos($array_temp[$i], ".");
            $str_temp .= "\n".substr($array_temp[$i], 0, $pos + 1);
        }
        if (stripos($array_temp[$i], "These updates") !== FALSE) {
            $str_temp .= "\n".$array_temp[$i];
        }
    }
    $ret_string = str_replace("<br>", " ", $str_temp);
    return $ret_string;
}
// Check whether is bug for AXS4
function is_bug_for_AXS4($xpath) {
    $query = "//div[@id='container']/div[@id='content']/table[@class='details']/tr[6]/td";
    $entries = $xpath->query($query);
    $products = $entries->item(0)->textContent;
    $rh_products = array(
        "Red Hat Enterprise Linux Server (v. 6)",
        "Red Hat Enterprise Linux High Availability (v. 6)",
        "Red Hat Enterprise Linux Server FasTrack (v. 6)",
        "Red Hat Enterprise Linux Resilient Storage (v. 6)",
        "Red Hat Enterprise Linux Load Balancer (v. 6)",
        "Red Hat Software Collections 1 for RHEL 6"
    );
    foreach ($rh_products as $rh_product) {
        if (strpos($products, $rh_product) !== FALSE) {
            return TRUE;
        }
    }
    return FALSE;
}
// Check whether is bug for AXS7
function is_bug_for_AXS7($xpath) {
    $query = "//div[@id='container']/div[@id='content']/table[@class='details']/tr[6]/td";
    $entries = $xpath->query($query);
    $products = $entries->item(0)->textContent;
    $rh_products = array(
        "Red Hat Enterprise Linux Server (v. 7)",
        "Red Hat Enterprise Linux High Availability (v. 7)",
        "Red Hat Enterprise Linux Server FasTrack (v. 7)",
        "Red Hat Enterprise Linux Resilient Storage (v. 7)",
        "Red Hat Software Collections 1 for RHEL 7"
    );
    foreach ($rh_products as $rh_product) {
        if (strpos($products, $rh_product) !== FALSE) {
            return TRUE;
        }
    }
    return FALSE;
}
// Get component of package, to be updated
function get_component($xpath) {
    $query = "//div[@id='container']/div[@id='content']/h1";
    $entries = $xpath->query($query);
    $products = $entries->item(0)->textContent;
    $component = "";
    if (strpos($products, "kernel") !== FALSE) {
        $component = "Kernel";
    }
    elseif (strpos($products, "anaconda") !== FALSE) {
        $component = "Installer";
    }
    else {
        $component = "Base system";
    }
    return $component;
}
// Get source time
function get_source_time($xpath) {
    $query = "//div[@id='container']/div[@id='content']/table[@class='details']/tr[4]/td";
    $entries = $xpath->query($query);
    $source_time = $entries->item(0)->textContent;
    return $source_time;
}
// Get CVE ids from RHN erratum xpath
function get_cve_id($xpath) {
    $query = "//div[@id='content']/table[@class='details']/tr[7]/td";
    $entries = $xpath->query($query);
    $string = $entries->item(0)->textContent;
    $cves = explode("CVE", $string);
    $temps = array_shift($cves);
    for ($i = 0; $i < count($cves); $i++) {
        $cves[$i] = "CVE".$cves[$i];
    }
    return $cves;
}
// Get list of SRPMs from RHN erratum xpath
function get_SRPMS($xpath, $product, $rhel_release) {
    $SRPMSs = array();
    $main_affected_product = get_primary_affected_product($xpath, $product);
    $i = 0;
    while(TRUE) {
        $query = "//div[@id='content']/table[2]/tr[td//text()[contains(., '".$main_affected_product."')]]/following-sibling::tr[3 + $i]/td[1]";
        $entries = $xpath->query($query);
        $SRPMS = $entries->item(0)->textContent;
        if (strpos($SRPMS, ".src.rpm") === FALSE) {
            break;
        }
        array_push($SRPMSs, $SRPMS);
        $i++;
    }
    if (count($SRPMSs) === 0) {
        return FALSE;
    }
    for ($i = 0; $i < count($SRPMSs); $i++) {
        $count = 0;
        for ($j = 0; $j < count($rhel_release); $j++) {
            if (strpos($SRPMSs[$i], $rhel_release[$j]) === FALSE) {
                $count++;
            }
        }
        if ($count === count($rhel_release)) {
            return FALSE;
        } 
    }
    return $SRPMSs;
}
// Get package name from a SRPMS
function get_package_name($SRPMS) {
    $pos1 = strrpos($SRPMS, "-");
    $str1 = substr($SRPMS, 0, $pos1);
    $pos2 = strrpos($str1, "-");
    $package_name = substr($str1, 0, $pos2);
    return $package_name;
}
// Get package description from package name
function get_package_description($package_name, $srpms_path) {
    $packages = glob("$srpms_path/$package_name-[!a-z]*");
    $package = $packages[count($packages) - 1];
    $package_description = shell_exec("rpm -qp --nosignature --qf \"%{DESCRIPTION}\" $package");
    return $package_description;
}
// Set operting system
function get_op_system() {
    return "Linux";
}
// Set platform
function get_platform() {
    return "All";
}
// Get assignment for bug
function get_assignment($pkg_name) {
    $kernel_cmp = strcmp($pkg_name, "kernel");

    if ($kernel_cmp == 0) {
        $dbfile = "/project/maintenance/errata/assignment/kernel";
    } else {
        $dbfile = "/project/maintenance/errata/assignment/bug";
    }

    $content = file($dbfile, FILE_IGNORE_NEW_LINES);
    $len = count($content) - 1;
    $engineers = array_slice($content, 0, $len);
    $current = (int)$content[$len];

    if (($current + 1) >= $len) {
        if ($kernel_cmp == 0) {
            $next = 1;
        } else {
            $next = 0;
        }
    } else {
        $next = $current + 1;
    }

    // update next at the last line
    exec('sed -i "\$ c'.$next.'" '.$dbfile);

    if ($kernel_cmp == 0) {
        $assignee = $engineers[0];
        $qa = $engineers[$current];
    } else {
        $assignee = $engineers[$current];
        $qa = $engineers[$next];
    }

    return array ($assignee, $qa);
}
// Get bug type
function get_bug_type($xpath) {
    $query = "//div[@id='container']/div[@id='content']/table[@class='details']/tr[1]/td";
    $entries = $xpath->query($query);
    $advisory = $entries->item(0)->textContent;
    if (strpos($advisory, "RHSA") !== FALSE) {
        return "security";
    }
    elseif (strpos($advisory, "RHBA") !== FALSE) {
        return "fixed";
    }
    else {
        return "enhancement";
    }
}
// Get priority from RHN erratum xpath
function get_priority($xpath) {
    $bug_type = get_bug_type($xpath);
    if (strpos($bug_type, "security") !== FALSE) {
        $severity = get_severity($xpath);
        if (strpos($severity, "2.critical") !== FALSE) {
            $priority = "1.Urgent";
        }
        elseif (strpos($severity, "3.major") !== FALSE) {
            $priority = "2.High";
        }
        elseif (strpos($severity, "4.normal") !== FALSE) {
            $priority = "3.Medium";
        }
        else {
            $priority = "4.Low";
        }
    }
    else {
        $priority = "3.Medium";
    }
    return $priority;
}
// Get severity from RHN erratum xpath
function get_severity($xpath) {
    $bug_type = get_bug_type($xpath);
    $query = "//div[@id='content']/h1";
    $entries = $xpath->query($query);
    $errata_title = $entries->item(0)->textContent;
    $pos = strpos($errata_title, ":");
    $severity = substr($errata_title, 0, $pos);
    $severity = trim($severity);
    if (strpos($bug_type, "security") !== FALSE) {
        if (strpos($severity, "Critical") !== FALSE) {
            $severity = "2.critical";
        }
        elseif (strpos($severity, "Important") !== FALSE) {
            $severity = "3.major";
        }
        elseif (strpos($severity, "Moderate") !== FALSE) {
            $severity = "4.normal";
        }
        else {
            $severity = "5.minor";
        }
    }
    elseif (strpos($bug_type, "fixed") !== FALSE) {
        $severity = "4.normal";
    }
    else {
        $severity = "7.enhancement";
    }
    return $severity;
}
// Set default alias
function get_alias() {
    return "";
}
// Set cc
function get_cc($xpath) {
    $cc = "";
    $default_cc = "ax-tech-notify@asianux.com";
    $query = "//div[@id='container']/div[@id='content']/h1";
    $entries = $xpath->query($query);
    $products = $entries->item(0)->textContent;
    if (strpos($products, "kernel") !== FALSE) {
        $cc = "ax-kernel@asianux.com, ".$default_cc; 
    }
    elseif (strpos($products, "anaconda") !== FALSE) {
        $cc = "ax-installer@asianux.com, ".$default_cc; 
    }
    else {
        $cc = $default_cc;
    }
    return $cc;
}
// Set default url
function get_url() {
    return "";
}
// Set default append command
function get_append_command() {
    return "";
}
// Prepare requires list
function make_require_list($SRPMSs, $repoid, $repofrompath, $filename) {
    $file = fopen($filename,"a");
    for ($i = 0; $i < count($SRPMSs); $i++) {
        fwrite($file, $SRPMSs[$i] . "\n");
        $file_arr = array();

        // get BuildRequires
        $buildreq_cmd = shell_exec("rpm -qpR $SRPMSs[$i] 2>/dev/null");
        $tmp_arr = explode("\n", $buildreq_cmd);
        for ($k = 0; $k < count($tmp_arr); $k++) {
            $tmp_arr[$k] = preg_replace("/ (>=|=|<=).*/i", "", $tmp_arr[$k]);
            if (preg_match("/\(.*\)/", $tmp_arr[$k]) or substr($tmp_arr[$k], 0, 1) == "/") {
                $tmp_arr[$k] = rtrim($tmp_arr[$k]);
                if (is_array($repoid) and is_array($repofrompath)) {
                    $tmp_pkgname = shell_exec("repoquery --repoid=$repoid[0] --repoid=$repoid[1] --repofrompath=$repofrompath[0] --repofrompath=$repofrompath[1] --whatprovides '$tmp_arr[$k]' | tail -n1");
                } else {
                    $tmp_pkgname = shell_exec("repoquery --repoid=$repoid --repofrompath=$repofrompath --whatprovides '$tmp_arr[$k]' | tail -n1");
                }
                $tmp_arr[$k] = get_package_name($tmp_pkgname);
            }
            array_push($file_arr, rtrim($tmp_arr[$k]));
        }

        // get Requires
        $spec_file = rtrim(shell_exec("rpm -qpl $SRPMSs[$i] 2>/dev/null | grep '\.spec'"));
        $req_cmd = shell_exec("rpm2cpio $SRPMSs[$i] | cpio -id; cat $spec_file | grep ^Requires:");
        $tmp_arr = explode("\n", $req_cmd);
        for ($k = 0; $k < count($tmp_arr); $k++) {
            $tmp_arr[$k] = preg_replace("/Requires:\s+(.*)/i", "$1", $tmp_arr[$k]);
            $tmp_arr[$k] = preg_replace("/(>=|=|<=).*/i", "", $tmp_arr[$k]);
            $tmp_arr[$k] = preg_replace("/.*\{.*\}.*/i", "", $tmp_arr[$k]);
            if (preg_match("/\(.*\)/", $tmp_arr[$k]) or substr($tmp_arr[$k], 0,1) == "/") {
                $tmp_arr[$k] = rtrim($tmp_arr[$k]);
                if (is_array($repoid) and is_array($repofrompath)) {
                    $tmp_pkgname = shell_exec("repoquery --repoid=$repoid[0] --repoid=$repoid[1] --repofrompath=$repofrompath[0] --repofrompath=$repofrompath[1] --whatprovides '$tmp_arr[$k]' | tail -n1");
                } else {
                    $tmp_pkgname = shell_exec("repoquery --repoid=$repoid --repofrompath=$repofrompath --whatprovides '$tmp_arr[$k]' | tail -n1");
                }
                $tmp_arr[$k] = get_package_name($tmp_pkgname);
            }
            if (strpos($tmp_arr[$k], ",")) {
                $ttmp_arr = explode(",", $tmp_arr[$k]);
                for ($j = 0; $j < count($ttmp_arr); $j++) {
                    array_push($file_arr, rtrim($ttmp_arr[$j]));
                }
            } elseif (strpos($tmp_arr[$k], " ")) {
                $ttmp_arr = explode(" ", $tmp_arr[$k]);
                for ($j = 0; $j < count($ttmp_arr); $j++) {
                    array_push($file_arr, rtrim($ttmp_arr[$j]));
                }
            } else {
                array_push($file_arr, rtrim($tmp_arr[$k]));
            }
        }
        $file_arr = array_values(array_unique($file_arr));
        for ($l = 0; $l < count($file_arr); $l++) {
            fwrite($file, $file_arr[$l] . "\n");
        }
        fwrite($file, "---------------\n");
    }
    fclose($file);
    shell_exec("sed -i '/^$/d' $filename");
}
// Set order multi packages
function set_order($srpm_pkg, $SRPMSs, $order_list, $loop_list, $filename, $builder) {
    if (in_array($srpm_pkg, $order_list)) {
        return $order_list;
    }

    $file_reqs = fopen($filename, "r");
    $buildreqs = array();
    $flag = 0;
    while (! feof($file_reqs)) {
        $line = rtrim(fgets($file_reqs));
        if ($line === $srpm_pkg)
            $flag = 1;
        if ($flag === 1) {
            if (strpos($line, "-----") !== FALSE)
                break;
            elseif ($line !== $srpm_pkg)
                array_push($buildreqs, $line);
        }
    }
    fclose($file_reqs);

    for ($i = 0; $i < count($SRPMSs); $i++) {
        if ($SRPMSs[$i] === $srpm_pkg) {
            continue;
        } else {
            $pid = getmypid();
            $WORK_DIR = getenv("HOME") . "/$pid/multi-pkg-list/";
            $spec_file = $WORK_DIR . rtrim(shell_exec("rpm -qpl $SRPMSs[$i] 2>/dev/null | grep '\.spec'"));
            $buildout_cmd = shell_exec("ssh -q $builder rpm -q --specfile $spec_file 2>/dev/null");
            $tmp_buildout = explode("\n", $buildout_cmd);
            $buildouts = array();
            for ($z = 0; $z < count($tmp_buildout); $z++) {
                $buildouts[$z] = get_package_name(rtrim($tmp_buildout[$z]));
            }

            $set_buildreq = 0;
            for ($k = 0; $k < count($buildreqs); $k++) {
                if (strpos($buildreqs[$k], get_package_name($SRPMSs[$i])) !== FALSE and in_array($buildreqs[$k], $buildouts)) {
                    if (!in_array($SRPMSs[$i], $loop_list)) {
                        $set_buildreq = 1;
                        array_push($loop_list, $SRPMSs[$i]);
                        $order_list = set_order($SRPMSs[$i], $SRPMSs, $order_list, $loop_list, $filename, $builder);
                    }
                }
                if (($k === (count($buildreqs) - 1)) and ($set_buildreq === 1)) {
                    if (in_array($SRPMSs[$i], $order_list)) {
                        break;
                    } else {
                        array_push($order_list, $SRPMSs[$i]);
                    }
                }
            }
        }
    }
    unset($loop_list);
    array_push($order_list, $srpm_pkg);
    return $order_list;
}
// Arrange multi packages
function arrange_multi_pkgs($product_number, $SRPMSs) {
    $pid = getmypid();
    $HOME = getenv("HOME");
    $filename = "$HOME/$pid.multipkg-buildrequire";
    $order_list = array();
    $loop_list = array();
    $tmp_arr = array();
    mkdir("$HOME/$pid/multi-pkg-list/", 0755, true);
    $current_dir = getcwd();
    chdir("$HOME/$pid/multi-pkg-list/");
    switch ($product_number) {
    case "4.0":
        $builder = "ax4-amd64-builder";
        $rhel6_srpm_url = "http://ftp.redhat.com/pub/redhat/linux/enterprise/6Server/en/os/SRPMS/";
        $srpm_location = "/project/pool/Hiranya/SRPMS.el6/";
        $repoid = array("4.0", "4.1");
        $repofrompath = array("4.0,http://repos.asianux.com/repos/asianux/Hiranya/x86_64/archive/RPMS/", "4.1,http://repos.asianux.com/repos/asianux/Hiranya/x86_64/pool/RPMS/");
        for ($i =0; $i < count($SRPMSs); $i++) {
            $srpm_url = $rhel6_srpm_url . $SRPMSs[$i];
            if (! file_exists($srpm_location . $SRPMSs[$i])) {
                shell_exec("curl -O $srpm_url");
                copy("$HOME/$pid/multi-pkg-list/" . $SRPMSs[$i], $srpm_location . $SRPMSs[$i]);
            } else {
                copy($srpm_location . $SRPMSs[$i], "$HOME/$pid/multi-pkg-list/" . $SRPMSs[$i]);
            }
        }
        make_require_list($SRPMSs, $repoid, $repofrompath, $filename);
        for ($i = 0; $i < count($SRPMSs); $i++) {
            $order_list = set_order($SRPMSs[$i], $SRPMSs, $order_list, $loop_list, $filename, $builder);
        }
        break;
    case "7.0":
        $builder = "ax7-amd64-builder";
        $srpm_location = "/project/pool/Lotus/SRPMS.el7/";
        $repoid = "7.0";
        $repofrompath = array("7.0,http://repos.asianux.com/repos/asianux/Lotus/x86_64/archive/RPMS/", "7.1,http://repos.asianux.com/repos/asianux/Lotus/x86_64/pool/RPMS/");
        for ($i = 0; $i < count($SRPMSs); $i++) {
            $pkg_name = get_package_name($SRPMSs[$i]);
            if (! file_exists($srpm_location . $SRPMSs[$i])) {
                shell_exec("ssh -q $builder /project/bin/generate-srpm.sh -p $pkg_name 2>/dev/null");
            }
            copy($srpm_location . $SRPMSs[$i], "$HOME/$pid/multi-pkg-list/" . $SRPMSs[$i]);
        }
        make_require_list($SRPMSs, $repoid, $repofrompath, $filename);
        for ($i = 0; $i < count($SRPMSs); $i++) {
            $order_list = set_order($SRPMSs[$i], $SRPMSs, $order_list, $loop_list, $filename, $builder);
        }
        break;
    }
    unlink($filename);
    chdir($current_dir);
    shell_exec("rm -rf $HOME/$pid/multi-pkg-list/");
    return $order_list;
}
# Get some variables from Jenkins
$axs4_srpms_path = getenv('AXS4_SRPMS_PATH');
$axs7_srpms_path = getenv('AXS7_SRPMS_PATH');
$RHEL6_DIST = getenv('RHEL6_DIST');
$RHEL7_DIST = getenv('RHEL7_DIST');
$rhel6_release = explode(",", $RHEL6_DIST);
$rhel7_release = explode(",", $RHEL7_DIST);
$axs4_product_stage = getenv('AXS4_PRODUCT_STAGE');
$axs7_product_stage = getenv('AXS7_PRODUCT_STAGE');
$axs4_version = getenv('AXS4_VERSION');
$axs7_version = getenv('AXS7_VERSION');
$cve_prefix_link = "http://cve.mitre.org/cgi-bin/cvename.cgi?name=";
date_default_timezone_set('America/New_York');
$spec_date = getenv("spec_date");
if (empty($spec_date)) {
    $current_date = date("Y-m-d", time());
} else {
    $current_date = $spec_date;
}
$spec_erratum = getenv("spec_erratum");
$list_errata_link = array();
$list_errata_page_axs4 = array(
    "https://rhn.redhat.com/errata/rhel-server-6-errata.html",
    "https://rhn.redhat.com/errata/rhel-ha-6-errata.html",
    "https://rhn.redhat.com/errata/rhel-server-fastrack-6-errata.html",
    "https://rhn.redhat.com/errata/rhel-rs-6-errata.html",
    "https://rhn.redhat.com/errata/rhel-lb-6-errata.html",
    "https://rhn.redhat.com/errata/rhel-6-rhscl-1-errata.html"
);
for ($i = 0; $i < count($list_errata_page_axs4); $i++) {
    if (!empty($spec_erratum)) {
        break;
    }
    $temp = select_erratum_to_file($list_errata_page_axs4[$i], $current_date);
    $list_errata_link = array_merge($list_errata_link, $temp);
}
$list_errata_page_axs7 = array(
    "https://rhn.redhat.com/errata/rhel-server-7-errata.html", 
    "https://rhn.redhat.com/errata/rhel-ha-7-errata.html", 
    "https://rhn.redhat.com/errata/rhel-server-fastrack-7-errata.html", 
    "https://rhn.redhat.com/errata/rhel-rs-7-errata.html", 
    "https://rhn.redhat.com/errata/rhel-7-rhscl-1-errata.html"
);
if (empty($spec_erratum)) {
    echo "Filing bugs for current date: ".$current_date."\n";
    for ($i = 0; $i < count($list_errata_page_axs7); $i++) {
        $temp = select_erratum_to_file($list_errata_page_axs7[$i], $current_date);
        $list_errata_link = array_merge($list_errata_link, $temp);
    }
}
else {
    echo "Forcing to file specific erratum $spec_erratum\n";
    $list_errata_link = array("https://rhn.redhat.com/errata/".$spec_erratum.".html");
}
// This help avoid duplicated bugs
$list_errata_link = array_unique($list_errata_link);
echo "List of errata now:\n";
for ($i = 0; $i < count($list_errata_link); $i++) {
    echo $list_errata_link[$i]."\n";
}
echo "Checking whether an erratum is filed...\n";
$list_temp = $list_errata_link;
$list_errata_link = array();
$list_errata_new_pkg = array();
foreach($list_temp as $element) {
    if (is_erratum_filed($element) === FALSE) {
        array_push($list_errata_link, $element);
    }
}
if (empty($list_errata_link)) {
    echo "There is no erratum to file.\n";
    exit;
}
echo "Errata to file: \n";
for ($i = 0; $i < count($list_errata_link); $i++) {
    echo $list_errata_link[$i]."\n";
}
if (update_filed_errata($list_errata_link)) {
    echo "Updated list of filed errata.\n";
}
else {
    echo "Failed to update list of filed errata.\n";
    exit;
}
for ($k = 0; $k < count($list_errata_link); $k++) {
    $xpath = load_errata_xpath($list_errata_link[$k]);
    $bug_type = get_bug_type($xpath);
    $component = escapeshellarg(get_component($xpath));
    $source_time = escapeshellarg(get_source_time($xpath));
    $severity = escapeshellarg(get_severity($xpath));
    $priority = escapeshellarg(get_priority($xpath));
    $op_system = escapeshellarg(get_op_system());
    $platform = escapeshellarg(get_platform());
    $errata_title = generate_bug_title($bug_type, $xpath);
    $errata_title = escapeshellarg($errata_title);
    if (strpos($errata_title, "new packages")) {
        array_push($list_errata_new_pkg, $list_errata_link[$k]);
    }
    $cc = get_cc($xpath);
    $cc = escapeshellarg($cc);
    echo "Summary: ".$errata_title."\n";
    $cve_description = "";
    if (get_cve($xpath) !== "") {
        $cve_description .= "Security issues fixed with this release:\n\n";
        $cve_description .= get_cve($xpath);
    }
    $description = get_description($xpath, $errata_title);

    # Get specific information for each products
    AXS4: {
        if (is_bug_for_AXS4($xpath) == TRUE) {
            $bug_1st_comment = "";
            echo "**** Filing a bug for AXS4 ****\n";
            $product = escapeshellarg("Asianux 4.0");
            $product_number = "4.0";
            $version = escapeshellarg($axs4_version);
            $target_milestone = $version;
            $srpms_path = $axs4_srpms_path;
            $rhel_release = $rhel6_release;
            $SRPMSs = array();
            $ret = get_SRPMS($xpath, $product, $rhel_release);
            if ($ret !== FALSE) {
                $SRPMSs = $ret;
            }
            else {
                echo "This package is not for RHEL versions: $RHEL6_DIST\n";
                goto AXS7;
            }
            $main_affected_product = get_primary_affected_product($xpath, $product);
            copy_SRPMS($product, $main_affected_product, $SRPMSs);
            $total_pkgs = count($SRPMSs);
            $outdated_erratum = check_outdated_errata($xpath, $product, $total_pkgs);
            if ($outdated_erratumx === TRUE) {
                echo "Erratum is outdated.\n";
                goto AXS7;
            }
            $whiteboard = ($total_pkgs > 1 ? "Multi" : "");
            $whiteboard = escapeshellarg($whiteboard);
            if ($whiteboard === "Multi") {
                $SRPMSs = arrange_multi_pkgs($product_number, $SRPMSs);
            }
            $package_names = array();
            for ($i = 0; $i < count($SRPMSs); $i++) {
                $package_names[$i] = get_package_name($SRPMSs[$i]);
            }
            $bug_1st_comment .= "Description:\n\n";
            for ($i = 0; $i < count($package_names); $i++) {
                if (strpos($errata_title, "new packages") === FALSE) {
                    if (!check_comps($package_names[$i], $product_number)) {
                        echo "Package $package_names[$i] is not in package list\n";
                        goto AXS7;
                    }
                }
            }
            for ($i = 0; $i < count($package_names); $i++) {
                if (count($package_names) > 1) {
                    $bug_1st_comment .= $package_names[$i]."\n";
                }
                $bug_1st_comment .= get_package_description($package_names[$i], $srpms_path)."\n\n";
            }
            $test_type = get_test_type($package_names, $product);
            list($assignee, $qa_contact) = get_assignment($package_names[0]);
            if (strcasecmp($test_type, "NT") == 0) {
                $qa_contact = "axbld@asianux.com";
            }
            $test_type = escapeshellarg($test_type);
            $bug_1st_comment .= "\n";
            if (!empty($cve_description)) {
                $bug_1st_comment .= $cve_description."\n";
            }
            $description = produce_AX_desc($description, $product);
            $bug_1st_comment .= $description;
            $errata_description = $bug_1st_comment;
            $bug_1st_comment .= "\n\n\n";
            $bug_1st_comment .= "SRPM(s):\n\n";
            for ($i = 0; $i < count($SRPMSs); $i++) {
                $bug_1st_comment .= $SRPMSs[$i]."\n";
            }
            $bug_1st_comment .= "\n\n";
            $modified_pkgs = get_modified_pkgs($package_names, $product);
            if (!empty($modified_pkgs)) {
                $bug_1st_comment .= "List of new or modified packages: \n\n";
                foreach ($modified_pkgs as $pkg) {
                    $bug_1st_comment .= $pkg." ";
                }
            }
            $bug_1st_comment .= "\n\n\n";
            $bug_1st_comment .= "Additional info:\n\n";
            $bug_1st_comment .= $list_errata_link[$k]."\n";
            $cves = get_cve_id($xpath);
            foreach ($cves as $cve) {
                $bug_1st_comment .= $cve_prefix_link.$cve."\n";
            }
            echo "Product: ".$product."\n";
            echo "Component: ".$component."\n";
            echo "Version: ".$version."\n";
            echo "Target Milestone: ".$target_milestone."\n";
            echo "Severity: ".$severity."\n";
            echo "OS: ".$op_system."\n";
            echo "Priority: ".$priority."\n";
            echo "Assignee: ".$assignee."\n";
            echo "QA: ".$qa_contact."\n";
            echo "Test type: ".$test_type."\n";
            echo "Type: ".$axs4_product_stage."\n";
            echo "Source Time: ".$source_time."\n";
            echo "CC list: ".$cc."\n";
            echo $bug_1st_comment."\n";
            $bug_1st_comment = escapeshellarg($bug_1st_comment);
            if (strpos($test_type, "---")) {
                $output = shell_exec("echo -ne '\n' | bugz --connection asianux post --product $product --component $component --version  $version --title $errata_title --description $bug_1st_comment --op-sys $op_system --platform $platform --priority $priority --severity $severity --assigned-to $assignee --qa-contact $qa_contact --alias \"\" --cc $cc --url \"\" --append-command \"\" --cf-sourcetime $source_time --cf-type $axs4_product_stage --target_milestone $target_milestone --whiteboard $whiteboard");
            }
            else {
                $output = shell_exec("echo -ne '\n' | bugz --connection asianux post --product $product --component $component --version  $version --title $errata_title --description $bug_1st_comment --op-sys $op_system --platform $platform --priority $priority --severity $severity --assigned-to $assignee --qa-contact $qa_contact --alias \"\" --cc $cc --url \"\" --append-command \"\" --cf-sourcetime $source_time --cf-type $axs4_product_stage --target_milestone $target_milestone --whiteboard $whiteboard --cf_testtype $test_type");
            }
            $bug_id = get_bug_id($output);
            echo "Bug url: https://bugzilla.asianux.com/show_bug.cgi?id=".$bug_id."\n";
            gen_build_order($bug_id, $SRPMSs);
            $ret = generate_errata_common($bug_id, $bug_type, $severity, $errata_description);
            if ($ret === FALSE) {
                echo "Failed to write errata description to file.\n";
                goto AXS7;
            }
            else {
                echo "Wrote errata type and errata description to file.\n";
            }
        }
    }
    AXS7: {
        if (is_bug_for_AXS7($xpath) == TRUE) {
            $bug_1st_comment = "";
            echo "**** Filing a bug for AXS7 ****\n";
            $product = escapeshellarg("Asianux 7.0");
            $product_number = "7.0";
            $version = escapeshellarg($axs7_version);
            $target_milestone = $version;
            $srpms_path = $axs7_srpms_path;
            $rhel_release = $rhel7_release;
            $SRPMSs = array();
            $package_names = array();
            $ret = get_SRPMS($xpath, $product, $rhel_release);
            if ($ret !== FALSE) {
                $SRPMSs = $ret;
            }
            else {
                echo "This package is not for RHEL versions: $RHEL7_DIST\n";
                goto AXSx;
            }
            $main_affected_product = get_primary_affected_product($xpath, $product);
            copy_SRPMS($product, $main_affected_product, $SRPMSs);
            $total_pkgs = count($SRPMSs);
            $outdated_erratum = check_outdated_errata($xpath, $product, $total_pkgs);
            if ($outdated_erratumx === TRUE) {
                echo "Erratum is outdated.\n";
                goto AXSx;
            }
            $whiteboard = ($total_pkgs > 1 ? "Multi" : "");
            $whiteboard = escapeshellarg($whiteboard);
            if ($whiteboard === "Multi") {
                $SRPMSs = arrange_multi_pkgs($product_number, $SRPMSs);
            }
            for ($i = 0; $i < count($SRPMSs); $i++) {
                $package_names[$i] = get_package_name($SRPMSs[$i]);
            }
            $bug_1st_comment .= "Description:\n\n";
            for ($i = 0; $i < count($package_names); $i++) {
                if (strpos($errata_title, "new packages") === FALSE) {
                    if (!check_comps($package_names[$i], $product_number)) {
                        echo "Package $package_names[$i] is not in package list\n";
                        goto AXSx;
                    }
                }
            }
            for ($i = 0; $i < count($package_names); $i++) {
                if (count($package_names) > 1) {
                    $bug_1st_comment .= $package_names[$i]."\n";
                }
                $bug_1st_comment .= get_package_description($package_names[$i], $srpms_path)."\n\n";
            }
            $test_type = get_test_type($package_names, $product);
            list($assignee, $qa_contact) = get_assignment($package_names[0]);
            if (strcasecmp($test_type, "NT") == 0) {
                $qa_contact = "axbld@asianux.com";
            }
            $test_type = escapeshellarg($test_type);
            $bug_1st_comment .= "\n";
            if (!empty($cve_description)) {
                $bug_1st_comment .= $cve_description."\n";
            }
            $description = produce_AX_desc($description, $product);
            $bug_1st_comment .= $description;
            $errata_description = $bug_1st_comment;
            $bug_1st_comment .= "\n\n\n";
            $bug_1st_comment .= "SRPM(s):\n\n";
            for ($i = 0; $i < count($SRPMSs); $i++) {
                $bug_1st_comment .= $SRPMSs[$i]."\n";
            }
            $bug_1st_comment .= "\n\n";
            $modified_pkgs = get_modified_pkgs($package_names, $product);
            if (!empty($modified_pkgs)) {
                $bug_1st_comment .= "List of new or modified packages: \n\n";
                foreach ($modified_pkgs as $pkg) {
                    $bug_1st_comment .= $pkg." ";
                }
            }
            $bug_1st_comment .= "\n\n\n";
            $bug_1st_comment .= "Additional info:\n\n";
            $bug_1st_comment .= $list_errata_link[$k]."\n";
            $cves = get_cve_id($xpath);
            foreach ($cves as $cve) {
                $bug_1st_comment .= $cve_prefix_link.$cve."\n";
            }
            echo "Product: ".$product."\n";
            echo "Component: ".$component."\n";
            echo "Version: ".$version."\n";
            echo "Target Milestone: ".$target_milestone."\n";
            echo "Severity: ".$severity."\n";
            echo "OS: ".$op_system."\n";
            echo "Priority: ".$priority."\n";
            echo "Assignee: ".$assignee."\n";
            echo "QA: ".$qa_contact."\n";
            echo "Test type: ".$test_type."\n";
            echo "Type: ".$axs7_product_stage."\n";
            echo "Source Time: ".$source_time."\n";
            echo "CC list: ".$cc."\n";
            echo $bug_1st_comment."\n";
            $bug_1st_comment = escapeshellarg($bug_1st_comment);
            if (strpos($test_type, "---")) {
                $output = shell_exec("echo -ne '\n' | bugz --connection asianux post --product $product --component $component --version  $version --title $errata_title --description $bug_1st_comment --op-sys $op_system --platform $platform --priority $priority --severity $severity --assigned-to $assignee --qa-contact $qa_contact --alias \"\" --cc $cc --url \"\" --append-command \"\" --cf-sourcetime $source_time --cf-type $axs7_product_stage --target_milestone $target_milestone --whiteboard $whiteboard");
            }
            else {
                $output = shell_exec("echo -ne '\n' | bugz --connection asianux post --product $product --component $component --version  $version --title $errata_title --description $bug_1st_comment --op-sys $op_system --platform $platform --priority $priority --severity $severity --assigned-to $assignee --qa-contact $qa_contact --alias \"\" --cc $cc --url \"\" --append-command \"\" --cf-sourcetime $source_time --cf-type $axs7_product_stage --target_milestone $target_milestone --whiteboard $whiteboard --cf_testtype $test_type");
            }
            $bug_id = get_bug_id($output);
            echo "Bug url: https://bugzilla.asianux.com/show_bug.cgi?id=".$bug_id."\n";
            gen_build_order($bug_id, $SRPMSs);
            $ret = generate_errata_common($bug_id, $bug_type, $severity, $errata_description);
            if ($ret === FALSE) {
                echo "Failed to write errata description to file.\n";
                goto AXSx;
            }
            else {
                echo "Wrote errata type and errata description to file.\n";
            }
        }
    }
    AXSx: {
        goto post_steps;
    }
    post_steps: {
        // Notify for errat with new packages
        if (count($list_errata_new_pkg) !== 0) {
            echo "Sending email to ax-tech about new packages.\n";
            notify_mail($list_errata_new_pkg);
            continue;
        }
    }
}
?>
