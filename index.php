<?php
##########
#This script takes the kea statistics-get-all api and returns the data in a telegraf-friendly format.
#Here is an example telegraf config that works with this script
#[[inputs.httpjson]]
#    name = "kea_stats"
#            servers = ["http://127.0.0.1/stats/"]
#            method = "GET"
#     tag_keys = [
#            "subnet"
#     ]
#In this example I am using the 'subnet' as a tag so grafana can easily access individual subnet information.
#As a bonus ./stats/?health produces a 'health' check that can be integrated with check_json in icinga
#########

$url = 'http://localhost:8000/';
$data = json_encode(array('command' => 'statistic-get-all', 'service' => array('dhcp4')));
$curl = curl_init($url);
curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
curl_setopt($curl,CURLOPT_RETURNTRANSFER, true);
$json_response = curl_exec($curl);
if (!$json_response) {
    $error['error'] = "No response from the kea API";
    print json_encode($error);
    exit;
}
if (isset($_GET['health'])) {
    $health['result'] = get_object_vars(json_decode($json_response)[0])['result'];
    $health['text'] = (isset(get_object_vars(json_decode($json_response)[0])['text']) ? get_object_vars(json_decode($json_response)[0])['text'] : 'Intentionally left blank');
    print json_encode($health);
    exit;
}

#contains the raw response from kea
$stats = json_decode($json_response);
#minimal error checking to a valid response
if (get_object_vars($stats[0])['result'] == 1) {
    $error['error'] = get_object_vars($stats[0])['text'];
   print json_encode($error);
   exit;
    }
    
#This is a bit embarrasing, as I am sure there is a less hacky way to do this
#Converts 'ClassStdObjects' to Arrays
$result = get_object_vars(get_object_vars($stats['0'])['arguments']); 

#Need to loop through the result first to collect all of the subnets defined
foreach ($result as $key => $value){
	if (preg_match('/subnet\[(\d*)\]/',$key,$matches,PREG_OFFSET_CAPTURE)) {
	   $subnetlist[] = $matches[1][0];
	}
}
$subnetlist = array_values(array_unique($subnetlist));

#With all of the subnets defined, we can restructure the output to be telegraf friendly
foreach ($subnetlist as $subnetkey => $subnetvalue) {
	foreach ($result as $key => $value) {
		#We need to pivot the information to group on a per subnet basis rather than a per metric basis
        #Looping through all of the subnet fields on a per subnet-id basis
		if (preg_match('/subnet/',$key)){
			if (preg_match('/subnet\['.preg_quote($subnetvalue).'\]/',$key)) {
				$newkey = preg_replace('/subnet\['.preg_quote($subnetvalue).'\]\./','',$key);
				$subnetinfo[subnet] = $subnetvalue;
		   		$subnetinfo[$newkey] = $result[$key][0][0];
                if(strtotime($result[$key][0][1]) > strtotime($subnetinfo['lastUpdated'])){
                    $subnetinfo['lastUpdated'] = $result[$key][0][1];
                }
			}
		}
		else {
			$betterjson[0][$key] = $value[0][0];
		}
		
	}
	$betterjson[] = $subnetinfo;
    #Make sure to clear stats between subnets
    unset($subnetinfo);
}

print(json_encode($betterjson,JSON_PRETTY_PRINT));
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
?>
