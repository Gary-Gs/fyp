<?php
require_once('../../../Connections/db_ntu.php');
require_once('./entity.php');
//require_once('../../../PHPExcel.php');
require_once('../../../CSRFProtection.php');
require_once('../../../Utility.php');
require_once ('../../../vendor/autoload.php');
?>
<?php

$csrf = new CSRFProtection();

$FILENAME = "AllocationOutput_" . date('d_M_Y'). ".xlsx";

/* Prepare data from database */
$staffList = array();
$projectList = array();
$unallocated_projects = array();

$query_rsSettings 	= "SELECT * FROM ".$TABLES['allocation_settings_general']." as g";
//$query_rsRoom		= "SELECT * FROM ".$TABLES['allocation_result_room']." ORDER BY `id` ASC";
//$query_rsDay 		= "SELECT max(`day`) as day FROM ".$TABLES['allocation_result_timeslot'];
$query_rsDay 		= "SELECT count(*) as number_of_days FROM ".$TABLES['allocation_settings_general']. " WHERE opt_out = 0";
$query_rsTimeslot  	= "SELECT * FROM ".$TABLES['allocation_result_timeslot']." ORDER BY `id` ASC";
$query_rsStaff		= "SELECT s.id as staffid, s.name as staffname, s.position as salutation FROM ".$TABLES['staff']." as s";
$query_rsProject = "SELECT r.project_id as pno, p.staff_id as staffid, r.examiner_id as examinerid, r.day as day, r.slot as slot, r.room as room FROM ".$TABLES['allocation_result']." as r LEFT JOIN ".$TABLES['fyp_assign']." as p ON r.project_id = p.project_id";
$query_rsDates = "SELECT alloc_date FROM ".$TABLES['allocation_settings_general'];
$query_examining_staff = "select * from staff where examine=1";
$query_supervising_load = "select * from staff, v_supervising_count where staff.id=v_supervising_count.staff_id and staff.examine=1";
$query_examining_load = "select * from staff, v_examiner_count where staff.id=v_examiner_count.examiner_id and staff.examine=1";
$query_preferredProjects = "select lower(staff_id) as staff_id, count(prefer) as prefer from fea_staff_pref where prefer like '%SC%' and archive=0 group by staff_id";
$query_notPreferred = "select r.examiner_id as id , count(r.project_id) as not_preferred from fea_result as r where concat(r.examiner_id,\" \", r.project_id) not in ( (select concat(lower(p.staff_id),\" \", p.prefer) as result from fea_staff_pref as p where prefer like '%SC%' and archive=0)) group by id";

try
{
	$settings 	= $conn_db_ntu->query($query_rsSettings)->fetch();
	//$rooms		= $conn_db_ntu->query($query_rsRoom);
	$timeslots	= $conn_db_ntu->query($query_rsTimeslot);
	$rsDay		= $conn_db_ntu->query($query_rsDay)->fetch();
	$staffs		= $conn_db_ntu->query($query_rsStaff);
	$projects 	= $conn_db_ntu->query($query_rsProject);
	$rsDates = $conn_db_ntu->query($query_rsDates)->fetchAll();
	$supervising_load = $conn_db_ntu->query($query_supervising_load)->fetchAll();
	$examining_load = $conn_db_ntu->query($query_examining_load)->fetchAll();
	$examining_staff = $conn_db_ntu->query($query_examining_staff)->fetchAll();
	$preferredProjects = $conn_db_ntu->query($query_preferredProjects)->fetchAll();
	$notPreferred = $conn_db_ntu->query($query_notPreferred)->fetchAll();
}
catch (PDOException $e)
{
	die($e->getMessage());
}

//Parse Alloc Date
try
{
	$startDate 		 	= DateTime::createFromFormat('Y-m-d', $settings['alloc_date']);
	$exam_dates = array();
	for ($i=0; $i<count($rsDates);$i++) {
		$date = strtotime($rsDates[$i]['alloc_date']);
		$newFormat = date('d/m/Y',$date);
		$exam_dates[$i] = $newFormat;
	}
}
catch(Exception $e)
{
	//Default Values
	$startDate 			= new DateTime();
}

//Timeslots
$NO_OF_DAYS = $rsDay['number_of_days'];
for($day=1; $day<=$NO_OF_DAYS; $day++)
	$timeslots_table[$day] = array();

foreach ($timeslots as $timeslot)
{
	$timeslots_table[ $timeslot['day'] ][ $timeslot['slot'] ] 	= new Timeslot( $timeslot['id'],
		$timeslot['day'],
		$timeslot['slot'],
		DateTime::createFromFormat('H:i:s', $timeslot['time_start']),
		DateTime::createFromFormat('H:i:s', $timeslot['time_end']));
}

//Rooms
$rooms_table = array();
//foreach($rooms as $room)
//{
//	$rooms_table[ $room['id'] ] = new Room(	$room['id'],
//											$room['roomName']);
//}

//Staff
foreach($staffs as $staff) { //Index Staff by staffid
	$staffList[ $staff['staffid'] ] = new Staff($staff['staffid'],
		$staff['salutation'],
		$staff['staffname']);
}

//Projects
foreach($projects as $project) { //Index Project By pno
	$projectList[ $project['pno'] ] = new Project(	$project['pno'],
		$project['staffid'],
		$project['examinerid'],
		'-' );

	$projectList [ $project['pno'] ]->assignTimeslot( $project['day'],
		$project['room'],
		$project['slot']);
}

//Unallocated Projects
foreach($projectList as $project) {
	if ( !$project->hasValidTimeSlot() && array_key_exists ($project->getID(), $projectList) )
	{
		$unallocated_projects[] = $projectList [ $project->getID() ];
	}
}
/*function getRooms ($day) {

global $conn_db_ntu, $TABLES;

$stmt1 = $conn_db_ntu->prepare("SELECT roomArray FROM ".$TABLES['allocation_settings_room']." WHERE day = ? ");
$stmt1->bindParam("1", $day);
$stmt1->execute();
$rooms = $stmt1->fetchAll();

if (sizeof ($rooms)>0){
    $roomsNewArr = (array) json_decode($rooms[0]["roomArray"]);
    for($j=1;$j<=sizeof($roomsNewArr);$j++) {
    $rooms_table[] = new Room((string)$j, $roomsNewArr[$j]);
  }
  return $rooms_table;
}
else {
    return null;
}



}*/


/* Write to Excel */
//$objPHPExcel = new PHPExcel();
$objPHPExcel = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
//Set properties
//$objPHPExcel->getProperties()->setCreator("creator");
//$objPHPExcel->getProperties()->setLastModifiedBy("modifiedBy");
//$objPHPExcel->getProperties()->setTitle("docTitle");
//$objPHPExcel->getProperties()->setSubject("subject");
//$objPHPExcel->getProperties()->setDescription("desc");


function cellColor($cells,$color){
	global $objPHPExcel;
	$objPHPExcel->getActiveSheet()->getStyle($cells)->getFill()->applyFromArray(array(
		'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
		//'type' => PHPExcel_Style_Fill::FILL_SOLID,
		'startColor' => array( 'rgb' => $color )
	));
}

function autosize_currentSheet()
{
	global $objPHPExcel;

	$objPHPExcel->getActiveSheet()->getColumnDimension('A')->setWidth(9);

	foreach (range('B', $objPHPExcel->getActiveSheet()->getHighestDataColumn()) as $col) {
		$objPHPExcel->getActiveSheet()
			->getColumnDimension($col)
			->setAutoSize(true);
	}
}

function getDay($day)
{
//		global $startDate;
//
	if ($day === null || $day == -1) return "-";
//
//		$calculatedDate = clone $startDate;
//		$day_interval	= new DateInterval('P'.($day-1).'D');	//Offset -1 because day 1 falls on startDate
//		$calculatedDate->add($day_interval);
//
//		return $calculatedDate->format('d/m/Y');
	global $exam_dates;
	return $exam_dates[$day-1];
}

//Default Styles
$objPHPExcel->getDefaultStyle()	->getFont()
	->setName('Arial')
	->setSize(10);

//Sheet 1 - Projects Allocated
//Create Header
$objPHPExcel->createSheet();
$objPHPExcel->setActiveSheetIndex(0);
$objPHPExcel->getActiveSheet()->setTitle('ProjectsAllocated');

$headers = ['SNo.', 'Project No', 'Supervisor', 'Supervisor Network A/C', 'Examiner', 'Examiner Network A/C', 'Room Number', 'Day No', 'Date', 'Timeslot'];
$objPHPExcel->getActiveSheet()->fromArray($headers, NULL, 'A1');
$objPHPExcel->getActiveSheet()->getStyle('A1:J1')->getFont()->setBold(true);

$rowCount = 2;	//First Data Row, excluding header
foreach ($projectList as $project)
{
	if ( !$project->hasValidTimeSlot() ) continue;

	$cur_supervisor = $project->getStaff();
	$cur_supervisor_name = $cur_supervisor;

	if ( array_key_exists($cur_supervisor, $staffList) )
		$cur_supervisor_name = $staffList[ $cur_supervisor ]->getName();

	$cur_examiner = $project->getExaminer();
	$cur_examiner_name = $cur_examiner;

	if ( array_key_exists($cur_examiner, $staffList) )
		$cur_examiner_name = $staffList[ $cur_examiner ]->getName();



	$cur_day = $project->getAssigned_Day();
	$cur_slot = $project->getAssigned_Time();

	if ( array_key_exists($cur_day, $timeslots_table) && array_key_exists($cur_slot, $timeslots_table[$cur_day]) )
		$cur_slot = $timeslots_table[ $cur_day ][ $cur_slot ]->toExcelString();
	else {
		$cur_slot = '-';
	}
	if ($cur_day <= 0)
	{
		$cur_day = '-';
		$cur_date = '-';
	}
	else{
		$cur_date = getDay($cur_day);
	}
	$cur_room = $project->getAssigned_Room();

	$rooms_table = retrieveRooms ($cur_day, "allocation_result_room");
	if (!isset ($rooms_table)) {

		echo "room table null";
		exit;
	}

	$curIndex = $cur_room -1;
	if ( array_key_exists($curIndex , $rooms_table) ) {

		$cur_room = $rooms_table[$curIndex]->toString();
		//echo("found room: ");
		//echo($cur_room);
		//echo "<br>";
	}
	else {
		$cur_room = '-';
	}
	$objPHPExcel->getActiveSheet()->SetCellValue('A'.$rowCount, $rowCount-1);					//SNo
	$objPHPExcel->getActiveSheet()->SetCellValue('B'.$rowCount, $project->getID());				//Project Code
	$objPHPExcel->getActiveSheet()->SetCellValue('C'.$rowCount, $cur_supervisor_name);			//Supervisor
	$objPHPExcel->getActiveSheet()->SetCellValue('D'.$rowCount, $cur_supervisor);				//Supervisor Network Account
	$objPHPExcel->getActiveSheet()->SetCellValue('E'.$rowCount, $cur_examiner_name);			//Examiner
	$objPHPExcel->getActiveSheet()->SetCellValue('F'.$rowCount, $cur_examiner);					//Examiner
	$objPHPExcel->getActiveSheet()->SetCellValue('G'.$rowCount, $cur_room);						//Room Number
	$objPHPExcel->getActiveSheet()->SetCellValue('H'.$rowCount, $cur_day);						//Day No
	$objPHPExcel->getActiveSheet()->SetCellValue('I'.$rowCount, $cur_date);						//Date
	$objPHPExcel->getActiveSheet()->SetCellValue('J'.$rowCount, $cur_slot);						//Timeslot

	if (($rowCount%2) == 0)	//Even Rows
		cellColor('A'.$rowCount.':J'.$rowCount, 'FFFF99');
	else
		cellColor('A'.$rowCount.':J'.$rowCount, 'CCFFCC');

	$rowCount++;
}

//Autosize Sheet 1
autosize_currentSheet();

//Sheet 2 - Projects UnAllocated
//Create Header
$objPHPExcel->setActiveSheetIndex(1);
$objPHPExcel->getActiveSheet()->setTitle('ProjectsUnallocated');

$headers = ['SNo.', 'Project No', 'Supervisor', 'Supervisor Network A/C', 'Examiner', 'Examiner Network A/C'];
$objPHPExcel->getActiveSheet()->fromArray($headers, NULL, 'A1');
$objPHPExcel->getActiveSheet()->getStyle('A1:F1')->getFont()->setBold(true);

$rowCount = 2;	//First Data Row, excluding header
foreach ($unallocated_projects as $project)
{
	$cur_supervisor = $project->getStaff();
	$cur_supervisor_name = $cur_supervisor;
	if ( array_key_exists($cur_supervisor, $staffList) )
		$cur_supervisor_name = $staffList[ $cur_supervisor ]->getName();

	$cur_examiner = $project->getExaminer();
	$cur_examiner_name = $cur_examiner;
	if ( array_key_exists($cur_examiner, $staffList) )
		$cur_examiner_name = $staffList[ $cur_examiner ]->getName();

	$objPHPExcel->getActiveSheet()->SetCellValue('A'.$rowCount, $rowCount-1);					//SNo
	$objPHPExcel->getActiveSheet()->SetCellValue('B'.$rowCount, $project->getID());				//Project Code
	$objPHPExcel->getActiveSheet()->SetCellValue('C'.$rowCount, $cur_supervisor_name);			//Supervisor
	$objPHPExcel->getActiveSheet()->SetCellValue('D'.$rowCount, $cur_supervisor);				//Supervisor Network Account
	$objPHPExcel->getActiveSheet()->SetCellValue('E'.$rowCount, $cur_examiner_name);			//Examiner
	$objPHPExcel->getActiveSheet()->SetCellValue('F'.$rowCount, $cur_examiner);					//Examiner Network Account

	if (($rowCount%2) == 0)	//Even Rows
		cellColor('A'.$rowCount.':F'.$rowCount, 'FFFF99');
	else
		cellColor('A'.$rowCount.':F'.$rowCount, 'CCFFCC');

	$rowCount++;
}

//Autosize Sheet 2
autosize_currentSheet();

// Sheet 3 - staff load
$objPHPExcel->createSheet();
$objPHPExcel->setActiveSheetIndex(2);
$objPHPExcel->getActiveSheet()->setTitle('Staff Load');
$headers = ['Staff ID', 'Email', 'Staff Name', 'Workload', 'Exemption', 'Supervising Projects', "Examining Projects", "Total Load After Assignment", "Preferred Project No.", "No. Projects Not in Proj Pref List"];
$objPHPExcel->getActiveSheet()->fromArray($headers, NULL, 'A1');
$objPHPExcel->getActiveSheet()->getStyle('A1:J1')->getFont()->setBold(true);
$rowCount = 2;
foreach ($examining_staff as $staff) {
	$objPHPExcel->getActiveSheet()->SetCellValue('A'.$rowCount, $staff['id']);
	$objPHPExcel->getActiveSheet()->SetCellValue('B'.$rowCount, $staff['email']);
	$objPHPExcel->getActiveSheet()->SetCellValue('C'.$rowCount, $staff['name']);
	$objPHPExcel->getActiveSheet()->SetCellValue('D'.$rowCount, $staff['workload']);
	$objPHPExcel->getActiveSheet()->SetCellValue('E'.$rowCount, $staff['exemption']);
	foreach($supervising_load as $supervising) {
		if ($supervising['staff_id'] == $staff['id']) {
			if ($supervising['supervising_count']==null || $supervising['supervising_count']=="") {
				$objPHPExcel->getActiveSheet()->SetCellValue('F'.$rowCount, "0");
			}
			else {
				$objPHPExcel->getActiveSheet()->SetCellValue('F'.$rowCount, $supervising['supervising_count']);
			}

		}
	}
	foreach($examining_load as $examining) {
		if($examining['examiner_id'] == $staff['id']) {
			if ($examining['examiner_count']==null  || $examining['examiner_count']=="") {
				$objPHPExcel->getActiveSheet()->SetCellValue('G'.$rowCount, "0");
			}
			else {
				$objPHPExcel->getActiveSheet()->SetCellValue('G'.$rowCount, $examining['examiner_count']);
			}
		}
	}
	$totalLoad = $objPHPExcel->getActiveSheet()->getCell('D'.$rowCount)->getValue() + $objPHPExcel->getActiveSheet()->getCell('G'.$rowCount)->getValue();
	$objPHPExcel->getActiveSheet()->SetCellValue('H'.$rowCount, $totalLoad);

	foreach($preferredProjects as $preferred) {
		if ($preferred['staff_id'] == $staff['id']) {
			$objPHPExcel->getActiveSheet()->SetCellValue('I'.$rowCount, $preferred['prefer']);
		}
	}

	foreach ($notPreferred as $notPrefer) {
		if ($notPrefer['id'] == $staff['id']) {
			$objPHPExcel->getActiveSheet()->SetCellValue('J'.$rowCount, $notPrefer['not_preferred']);
		}
	}

	if(($rowCount%2) == 0)	//Even Rows
		cellColor('A'.$rowCount.':J'.$rowCount, 'FFFF99');
	else
		cellColor('A'.$rowCount.':J'.$rowCount, 'CCFFCC');

	$rowCount++;
}

//Autosize Sheet 3
autosize_currentSheet();

//Switch back to active sheet
$objPHPExcel->setActiveSheetIndex(0);

// Save Excel 2007 file
//$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);
$objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, "Xlsx");
$objWriter->save($FILENAME);

ob_start();
header('Content-disposition: attachment; filename='.$FILENAME);
header('Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Length: '.filesize($FILENAME));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: must-revalidate');
header('Pragma: no-cache');
ob_clean();
flush();
readfile($FILENAME);
$conn_db_ntu = null;
exit;
?>