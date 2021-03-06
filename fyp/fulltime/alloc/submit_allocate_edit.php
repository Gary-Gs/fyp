<?php require_once('../../../Connections/db_ntu.php');
	  require_once('../../../CSRFProtection.php');
	  require_once('../../../Utility.php');?>

<?php

	$csrf = new CSRFProtection();

	$_REQUEST['validate']=$csrf->cfmRequest();

	//Set Values (General)
	$error_code = -1;
	$projectID = null;

	if(isset($_REQUEST['user_id']) && isset($_REQUEST['project_id']))
	{
		$user = $_REQUEST['user_id'];
		$projectID = $_REQUEST['project_id'];


		$query_rsProjectAssign	= "SELECT * FROM ".$TABLES['allocation_result'] . " WHERE project_id = ?";

		try
		{	$stmt = $conn_db_ntu->prepare ($query_rsProjectAssign);
		    $stmt->bindParam(1, $projectID);
			$stmt->execute();
			$projectData =$stmt->fetch();


		}
		catch (PDOException $e)
		{

			die("1. ".$e->getMessage());
		}

		if ($projectData)	//Valid Project
		{

			$examinerID = (isset($_REQUEST['examiner'])) ? $_REQUEST['examiner'] : -2;


			$exam_day =  (isset($_REQUEST['exam_day'])) ? $_REQUEST['exam_day'] : -2;


			$exam_slotID =  (isset($_REQUEST['exam_slot'])) ? $_REQUEST['exam_slot'] : -2;


			$exam_room =  (isset($_REQUEST['exam_room'])) ? $_REQUEST['exam_room'] : -2;


			$hasEmpty = ($examinerID == -1 || $exam_day == -1 || $exam_slotID == -1 || $exam_room == -1);

			 if ($hasEmpty)
			{

				$updateQuery = "UPDATE ". $TABLES['allocation_result']. " SET day = NULL, slot = NULL, room = NULL, clash = 0 WHERE project_id = ?";
				$stmt = $conn_db_ntu->prepare ($updateQuery);
				$stmt->bindParam(1, $projectID);
				$stmt->execute();

				SystemLog($user, $updateQuery, "Delete allocation for $projectID");

			}
			else
			{

				//Update Examiner
				//$existExaminer = null;
				$query_rsExaminer = "SELECT * FROM ".$TABLES['staff']." WHERE id = ?";

				try
				{
					$stmt = $conn_db_ntu->prepare ($query_rsExaminer);
					$stmt->bindParam(1, $examinerID);
					$stmt->execute();
					$existExaminer =$stmt->fetch();


				}
				catch (PDOException $e)
				{
					die("2. ".$e->getMessage());
				}

				if ($existExaminer && $examinerID != $projectData['examiner_id'] )	//Valid Examiner and Examiner Changed
				{

					$updateQuery = "UPDATE ". $TABLES['allocation_result']. " SET examiner_id = ? WHERE project_id = ?";
					$stmt = $conn_db_ntu->prepare ($updateQuery);
					$stmt->bindParam(1, $examinerID);
					$stmt->bindParam(2, $projectID);
					$stmt->execute();
					SystemLog($user, $updateQuery, "Set $projectID allocated examiner to $examinerID");

				}

				//Update Examination Day
				//$existDay = null;
				/*$query_rsDay = "SELECT * FROM ".$TABLES['allocation_result']." WHERE day= ?";
				try
				{	$stmt = $conn_db_ntu->prepare ($query_rsDay);
					$stmt->bindParam(1, $exam_day);
					$stmt->execute();
					$existDay  = $stmt->fetch();

					//$existDay	= $conn_db_ntu->query($query_rsDay)->fetch();
				}
				catch (PDOException $e)
				{

					die("3. ".$e->getMessage());
				}

				if ($existDay && $exam_day != $projectData['day'] )	//Valid Day and Day Changed
				{


					$updateQuery = "UPDATE ". $TABLES['allocation_result']. " SET day = ? WHERE project_id = ?";
					$stmt = $conn_db_ntu->prepare ($updateQuery);
					$stmt->bindParam(1, $exam_day);
					$stmt->bindParam(2, $projectID);
					$stmt->execute();
					SystemLog($user, $updateQuery, "Set $projectID allocated day to $exam_day");
					//$conn_db_ntu->exec($updateQuery);
				}*/

				//Update Examination day and Slot
				//$existSlot = null;
				$query_rsSlot = "SELECT * FROM ".$TABLES['allocation_result_timeslot']." WHERE `id`= ? ";


				try
				{
					$stmt = $conn_db_ntu->prepare ($query_rsSlot);

					$stmt->bindParam(1, $exam_slotID);
					$stmt->execute();
					$existSlot   = $stmt->fetch();

				}
				catch (PDOException $e)
				{
					die("4. ".$e->getMessage());
				}





				if ($existSlot )	//Valid Slot
				{
					$exam_slot = $existSlot["slot"];
					$updateQuery = "UPDATE ". $TABLES['allocation_result']. " SET day= ?, slot = ? WHERE project_id = ?";
					$stmt = $conn_db_ntu->prepare ($updateQuery);
					$stmt->bindParam(1, $exam_day);
					$stmt->bindParam(2, $exam_slot);
					$stmt->bindParam(3, $projectID);
					$stmt->execute();
					SystemLog($user, $updateQuery, "Set $projectID allocated day to $exam_day,  slot to $ $exam_slot");

				}

				//Update Examination Room
				//$existRoom = null;
				$query_rsRoom = "SELECT roomArray FROM ".$TABLES['allocation_result_room']." WHERE day = ?";
				try
				{

					$stmt = $conn_db_ntu->prepare ($query_rsRoom);
					$stmt->bindParam(1, $exam_day);
					$stmt->execute();
					$existRoom  = $stmt->fetch();
				}
				catch (PDOException $e)
				{
					die("5. ".$e->getMessage());
				}

				if ($existRoom && $exam_room != $projectData['room'] )	//Valid Room and Room Changed
				{
					$roomValid= false;

					$roomArray  = (array)json_decode($existRoom[0]);
					for ($i=1;$i<=sizeof($roomArray);$i++) {
						//echo ("room array: ".$roomArray[$i]);
						//echo "<br>";
						//echo ("use room" .$exam_room);
						if ($i == $exam_room) {
							$roomValid = true;
							break;
						}


					}

					if ($roomValid) {

						$updateQuery = "UPDATE ". $TABLES['allocation_result']. " SET room = ? WHERE project_id = ?";
						$stmt = $conn_db_ntu->prepare ($updateQuery);
						$stmt->bindParam(1, $exam_room);
						$stmt->bindParam(2, $projectID);
						$stmt->execute();
						SystemLog($user, $updateQuery, "Set $projectID allocated room to $exam_room");

					}
				}
			}

			//Clash Calculation
			$resetClash = sprintf("UPDATE %s SET clash=0", $TABLES['allocation_result']);
			$conn_db_ntu->exec($resetClash);

			$clashQuery = sprintf("SELECT f1.project_id as pid FROM %s f1, %s f2 WHERE f1.project_id<>f2.project_id AND f1.day=f2.day AND f1.room=f2.room AND f1.slot=f2.slot",
									$TABLES['allocation_result'],
									$TABLES['allocation_result']);
			$rs_clash = $conn_db_ntu->query($clashQuery)->fetchAll();
			foreach($rs_clash as $clash)
			{
				if ($clash['pid'] == $projectID) $error_code = 1;	//Has Clash

				$setClash = "UPDATE ". $TABLES['allocation_result']. " SET clash= 1 WHERE project_id = ?";
				$stmt = $conn_db_ntu->prepare ($setClash);
				$stmt->bindParam(1, $clash['pid']);
				$stmt->execute();

			}
		}

		if ($error_code == -1)
			$error_code = 0;
	}

	$conn_db_ntu = null;
?>
<?php
if (isset ($_REQUEST['validate'])) {
	header("location:examiner_setting.php?validate=1");
} else if ($projectID != null) {
	$_SESSION['allocate_edit_project'] = $projectID;
	$_SESSION['allocate_edit_msg'] = ($error_code == 1) ? "warn" : "save";
	header("location:allocation_edit.php");
} else {
	header("location:allocation.php");
}
exit;
?>
