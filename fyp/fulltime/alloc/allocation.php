<?php
require_once('../../../Connections/db_ntu.php');
require_once('./entity.php');
require_once('../../../CSRFProtection.php');
require_once('../../../Utility.php');
require_once('../../../restriction.php'); ?>

<?php
$csrf = new CSRFProtection();

$query_rsSettings = "SELECT * FROM " . $TABLES['allocation_settings_general'] . " as g";
$query_rsTimeslot = "SELECT * FROM " . $TABLES['allocation_result_timeslot'] . " ORDER BY `id` ASC";
//$query_rsDay = "SELECT max(`day`) as day FROM ".$TABLES['allocation_result_timeslot'];
$query_rsDay = "SELECT count(*) as number_of_days FROM " . $TABLES['allocation_settings_general'] . " WHERE opt_out = 0";
//$query_rsRoom = "SELECT * FROM ".$TABLES['allocation_result_room']." ORDER BY `id` ASC";
$query_rsStaff = "SELECT s.id as staffid, s.name as staffname, s.position as salutation FROM " . $TABLES['staff'] . " as s";
$query_rsDates = "SELECT alloc_date FROM " . $TABLES['allocation_settings_general'];
$query_rsAllocation = "SELECT t1.project_id, t2.staff_id, t1.examiner_id, t1.day, t1.slot, t1.room, t1.clash FROM " . $TABLES['allocation_result'] . " as t1 JOIN " . $TABLES['fyp_assign'] . " as t2 ON t1.project_id = t2.project_id ";


try {
    $settings = $conn_db_ntu->query($query_rsSettings)->fetch();
    $rsTimeslot = $conn_db_ntu->query($query_rsTimeslot)->fetchAll();
    $rsDay = $conn_db_ntu->query($query_rsDay)->fetch();

    $staffs = $conn_db_ntu->query($query_rsStaff);
    $rsAllocation = $conn_db_ntu->query($query_rsAllocation);
    $rsDates = $conn_db_ntu->query($query_rsDates)->fetchAll();

} catch (PDOException $e) {
    die($e->getMessage());
}

//Parse Alloc Date
try {
    $startDate = DateTime::createFromFormat('Y-m-d', $settings['alloc_date']);
    $exam_dates = array();
    for ($i = 0; $i < count($rsDates); $i++) {
        $date = strtotime($rsDates[$i]['alloc_date']);
        $newFormat = date('d/m/Y', $date);
        $exam_dates[$i] = $newFormat;
    }
} catch (Exception $e) {
    //Default Values
    $startDate = new DateTime();
}

//Timeslots
$NO_OF_DAYS = $rsDay['number_of_days'];
for ($day = 1; $day <= $NO_OF_DAYS; $day++) {
    $timeslots_table[$day] = array();
}
$day = 0;

//foreach ($rsTimeslot as $timeslot) {
//	if ($day != $timeslot['day']) {
//		$day++;
//		$count = 0;
//	}
//	$timeslots_table[$timeslot['day']][++$count] = new Timeslot($timeslot['id'],
//		$timeslot['day'],
//		$timeslot['slot'],
//		DateTime::createFromFormat('H:i:s', $timeslot['time_start']),
//		DateTime::createFromFormat('H:i:s', $timeslot['time_end']));
//
//}

foreach ($rsTimeslot as $timeslot) {
    $timeslots_table[$timeslot['day']][$timeslot['slot']] = new Timeslot($timeslot['id'],
        $timeslot['day'],
        $timeslot['slot'],
        DateTime::createFromFormat('H:i:s', $timeslot['time_start']),
        DateTime::createFromFormat('H:i:s', $timeslot['time_end']));
}

//foreach ($rsRoom as $room)
//{
//	$rooms_table[ $room['id'] ] = new Room(	$room['id'], $room['roomName']);
//}

//Staff
$staffList = array();
foreach ($staffs as $staff) { //Index Staff by staffid
    $staffList[$staff['staffid']] = new Staff($staff['staffid'],
        $staff['salutation'],
        $staff['staffname']);
}

function getActualDate($day) {
//	global $startDate;

    if ($day === null || $day == -1) return "-";
//	$calculatedDate = clone $startDate;
//	$day_interval = new DateInterval('P' . ($day - 1) . 'D');    //Offset -1 because day 1 falls on startDate
//	$calculatedDate->add($day_interval);
//	return $calculatedDate->format('d/m/Y');

    global $exam_dates;
    return $exam_dates[$day - 1];
}

function getStaff($s) {
    global $staffList;

    if ($s === null || $s == -1) return "-";
    if (!array_key_exists($s, $staffList)) return "?";
    return $staffList[$s]->toString();
}

function getRoom($s, $day) {
    $index = $s - 1;
    $rooms_table = retrieveRooms($day, "allocation_result_room");

    if ($s === null || $s == -1 || !array_key_exists($index, $rooms_table) || !(isset($rooms_table))) {
        return "-";
    }
    return $rooms_table[$index]->toString();
}

function getTimeSlot($d, $s) {
    global $timeslots_table;

    if ($d == null || $d == -1 || $s === null || $s == -1 || !array_key_exists($d, $timeslots_table) || !array_key_exists($s, $timeslots_table[$d])) return "-";
    return $timeslots_table[$d][$s]->toString();
}

?>
<!DOCTYPE HTML>

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <title>Allocation System</title>
    <?php require_once('../../../head.php'); ?>
    <style>
        .clash_tr {
            background: #FFFF00;
            font-weight: bold;
        }

        .clash_td {
            color: #FF0000;
        }
    </style>

</head>
<!-- InstanceEndEditable -->
<body>
<?php require_once('../../../php_css/headerwnav.php'); ?>

<div id="loadingdiv" class="loadingdiv">
    <img id="loadinggif" src="../../../images/loading.gif"/>
    <p>Allocating...</p>
</div>

<div style="margin-left: -15px;">
    <div class="container-fluid">
        <?php require_once('../../nav.php'); ?>

        <!-- Page Content Holder -->
        <div class="container-fluid">
            <h3>Examiner Allocation System for Full Time Projects</h3>
            <?php
            if (isset($_SESSION['scrape']) && $_SESSION['scrape'] > 0) {
                echo "<p class='success'> All research interests retrieved. " . "</p>";
                unset($_SESSION['scrape']);
            }
            if (isset($_SESSION['scrape']) && $_SESSION['scrape'] <= 0) {
                echo "<p class='warn'> No research interests retrieved. " . "</p>";
                unset($_SESSION['scrape']);
            }

            if (isset($_SESSION['error_timeslot'])) {
                $error_code = $_SESSION['error_timeslot'];

                switch ($error_code) {
                    case 0:
                        if (isset($_SESSION['allocate_timeslot'])) {
                            $allocate_code = $_SESSION['allocate_timeslot'];
                            if ($allocate_code == 1) {
                                echo "<p class='success'>Timeslot Allocation Complete.</p>";
                                unset($_SESSION['allocate_timeslot']);
                            } else {
                                echo "<p class='warn'>Timeslot Allocation may be incomplete.</p>";
                                unset($_SESSION['allocate_timeslot']);
                            }
                        }
                        break;
                    case 1:
                        echo "<p class='error'>Timeslot Allocation Failed: Please allocate examiner first before proceeding!</p>";
                        unset($_SESSION['error_timeslot']);
                        break;
                    case 2:
                        echo "<p class='error'>Timeslot Allocation Failed: Problem loading timetable settings.</p>";
                        unset($_SESSION['error_timeslot']);
                        break;
                    default:
                        echo "<p class='error'>Timeslot Allocation Failed: Unknown Error has occurred. </p>";
                        unset($_SESSION['error_timeslot']);
                        break;
                }
            }
            if (isset($_SESSION['error_examiner'])) {
                $error_code = $_SESSION['error_examiner'];

                switch ($error_code) {
                    case 0:
                        echo "<p class='success'>Examiner Allocation Complete.</p>";
                        unset($_SESSION['error_examiner']);
                        break;
                    case 1:
                        echo "<p class='error'>Examiner Allocation Failed: Please upload the examiner and examinable project list before proceeding!</p>";
                        unset($_SESSION['error_examiner']);
                        break;
                    default:
                        echo "<p class='error'>[Examiner Allocation] Unknown Error has occurred.</p>";
                        unset($_SESSION['error_examiner']);
                        break;
                }
            }
            if (isset($_REQUEST['call'])) {
                echo "<p class='warn'>[System] All Allocations cleared.</p>";
                unset($_SESSION['call']);
            }


            ?>
            <div style="float:right; padding-bottom:15px;">
                Number of Project Buffer:
                <input type="text" id="Total_BufferProjects" name="Total_BufferProjects" value="0" placeholder="0"/>
                <a href="allocation_timetable.php" class="btn bg-dark text-white"
                   style="width:105px; font-size:12px;" title="View Timetable">View Timetable</a>
                <a href="submit_download_timetable.php" class="btn bg-dark text-white" style="font-size:12px;"
                   title="Download Timetable">Download Timetable</a>
                <br/><br/>
                <a href="retrieve_research_interest.php" class="btn bg-dark text-white"
                   style="font-size:12px;" title="Retrieve Research Interests">Retrieve Research Interests</a>

                <button id="BTN_AllocationExaminer" class="btn bg-dark text-white" style="font-size:12px;"
                        title="Allocate Examiner">Allocate Examiner
                </button>
                <button id="BTN_AllocationTimeSlot" class="btn bg-dark text-white" style="font-size:12px;"
                        title="Allocate Timeslot">Allocate Timeslot
                </button>
                <button id="BTN_AllocationClear" class="btn bg-dark text-white" style="font-size:12px;"
                        title="Clear Allocation">Clear Allocation
                </button>
                <!-- For testing purposes -->
                <!-- <button id="BTN_AddStaffPref"  class="bt" style="width:105px;" title="Clear and Add Staff Pref">Add Staff Pref</button> -->
            </div>
            <div style="float:right; padding-bottom:15px;">
                <?php
                echo isset($_SESSION["total_projects"]) ? "Total Projects: " . $_SESSION["total_projects"] : "";
                ?>
            </div>
            <div class="table-responsive">
                <table border="1" cellpadding="0" cellspacing="0" width="100%">
                    <col width="12%"/>
                    <col width="22%"/>
                    <col width="22%"/>
                    <col width="4%"/>
                    <col width="15%"/>
                    <col width="15%"/>
                    <col width="10%"/>

                    <tr class="bg-dark text-white text-center">
                        <td>Project ID</td>
                        <td>Supervisor</td>
                        <td>Examiner</td>
                        <td>Day</td>
                        <td>Date</td>
                        <td>Timeslot</td>
                        <td>Room</td>
                    </tr>

                    <?php foreach ($rsAllocation as $row_rsAllocation) { ?>
                    <tr <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_tr"'; ?> >
                        <td>
                            <a id="<?php echo $row_rsAllocation['project_id']; ?>"
                               class="to_allocate_edit"><?php echo $row_rsAllocation['project_id']; ?></a>
                        </td>
                        <td <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_td"'; ?> ><?php echo getStaff($row_rsAllocation['staff_id']); ?></td>
                        <td <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_td"'; ?> ><?php echo getStaff($row_rsAllocation['examiner_id']); ?></td>
                        <td <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_td"'; ?> ><?php echo ($row_rsAllocation['day'] == "" || $row_rsAllocation['day'] == -1) ? '-' : $row_rsAllocation['day']; ?></td>

                        <td <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_td"'; ?> ><?php echo getActualDate($row_rsAllocation['day']); ?></td>
                        <td <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_td"'; ?> ><?php echo getTimeSlot($row_rsAllocation['day'], $row_rsAllocation['slot']); ?></td>
                        <td <?php if ($row_rsAllocation['clash'] == "1") echo 'class="clash_td"'; ?> ><?php echo getRoom($row_rsAllocation['room'], $row_rsAllocation['day']); ?></td>
                        <?php } ?>
                </table>
            </div>
            <br/>
        </div>

        <script type="text/javascript">
            $('.to_allocate_edit').click(function () {
                // alert(this.id);
                document.cookie = "temp_pid=" + this.id;
                document.location.href = 'allocation_edit.php';

            });
            // For Testing Purpose
            // $("#BTN_AddStaffPref").on("click",function(){
            // 	$.ajax({
            // 		url: 'submit_add_staffpref.php',
            // 		data: {"AlgorithmType" : $( "#AlgorithmType" ).val(),"Total_BufferProjects":$( "#Total_BufferProjects" ).val()},
            // 		type: 'POST',
            // 		success: function (data) {
            // 			console.log(data);
            // 			console.log("Ajax post success! ");
            // 			window.location.href = ("allocation.php?" + data);
            // 		},
            // 		error: function(data){
            // 			console.log("Ajax post failed!");
            // 		}
            // 	});
            // });
            $("#BTN_AllocationExaminer").click(function (e) {
                $("#loadingdiv").show();
                $.ajax({
                    url: 'submit_allocate_examiner.php',
                    data: {
                        "AlgorithmType": $("#AlgorithmType").val(),
                        "Total_BufferProjects": $("#Total_BufferProjects").val()
                    },
                    type: 'GET',
                    success: function (data) {
                        console.log(data);
                        console.log("Ajax post success! ");
                        // window.location.href = ("allocation.php?" + data);
                        location.reload();
                        $("#loadingdiv").hide();
                    },
                    error: function (data) {
                        console.log("Ajax post failed!");
                        $("#loadingdiv").hide();
                    }
                });
            });
            $("#BTN_AllocationTimeSlot").click(function (e) {
                $("#loadingdiv").show();
                $.ajax({
                    url: 'submit_allocate_timeslot.php',
                    type: 'GET',
                    success: function (data) {
                        console.log(data);
                        // window.location.href = ("allocation.php?" + data);
                        location.reload();
                        $("#loadingdiv").hide();
                    },
                    error: function (data) {
                        console.log("Server error");
                        $("#loadingdiv").hide();
                    }
                });
            });
            $("#BTN_AllocationClear").click(function (e) {
                $.ajax({
                    url: 'submit_allocate_clear.php',
                    type: 'GET',
                    success: function (data) {
                        console.log(data);
                        console.log("Ajax post success!");
                        // window.location.href = ("allocation.php?" + data);
                        location.reload();
                    },
                    error: function (data) {
                        console.log("Ajax post failed!");
                    }
                });
            });
        </script>
        <!-- closing navigation div in nav.php -->
    </div>
</div>
<?php require_once('../../../footer.php'); ?>
</body>
<!-- InstanceEnd -->
</html>
<?php
unset($rsTimeslot);
unset($rsRoom);
unset($staffList);
unset($rsAllocation);
$conn_db_ntu = null;
?>
