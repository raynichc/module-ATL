<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Domain\Rubrics\RubricGateway;
use Gibbon\Domain\Timetable\CourseEnrolmentGateway;
use Gibbon\Domain\Timetable\CourseGateway;
use Gibbon\Domain\User\RoleGateway;
use Gibbon\Module\ATL\Domain\ATLColumnGateway;
use Gibbon\Module\ATL\Domain\ATLEntryGateway;
use Gibbon\Services\Format;

//Rubric includes
include './modules/Rubrics/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/ATL/atl_write.php') == false
    && isActionAccessible($guid, $connection2, '/modules/ATL/atl_write_student.php') == false) {
    //Acess denied
    $page->addError(__('Your request failed because you do not have access to this action.'));
} else {
    //Proceed!
    //Check if school year specified
    $gibbonCourseClassID = $_GET['gibbonCourseClassID'] ?? '';
    $atlColumnID = $_GET['atlColumnID'] ?? '';
    $gibbonPersonID = $_GET['gibbonPersonID'] ?? '';
    $gibbonRubricID = $_GET['gibbonRubricID'] ?? '';
    if (empty($gibbonCourseClassID) || empty($atlColumnID) || empty($gibbonPersonID) || empty($gibbonRubricID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
    } else {
        $type = $_GET['type'] ?? '';
        $contextDBTableGibbonRubricIDField = '';
        if ($type == 'attainment') {
            $contextDBTableGibbonRubricIDField = 'gibbonRubricIDAttainment';
        } else {
            $contextDBTableGibbonRubricIDField = 'gibbonRubricID';
        }

        $allowed = false;
        $roleGateway = $container->get(RoleGateway::class);
        $roleCategory = $roleGateway->getByID($session->get('gibbonRoleIDPrimary'))['category'] ?? '';

        if ($roleCategory == 'Staff') {
            $courseGateway = $container->get(CourseGateway::class);
            $result = $courseGateway->getCourseClassByID($gibbonCourseClassID);
            $allowed = !empty($result);
        } elseif ($roleCategory == 'Student' || $roleCategory == 'Parent') {
            $courseEnrolmentGateway = $container->get(CourseEnrolmentGateway::class);
            $result = $courseEnrolmentGateway->selectBy(['gibbonPersonID' => $gibbonPersonID, 'gibbonCourseClassID' => $gibbonCourseClassID, 'role' => 'Student']);
            $allowed = $result->isNotEmpty();

        }

        if (!$allowed) {
            $page->addError(__('The selected record does not exist, or you do not have access to it.'));
        } else {
            $atlColumnGateway = $container->get(ATLColumnGateway::class);
            if (!$atlColumnGateway->exists($atlColumnID)) {
                $page->addError(__('The selected record does not exist, or you do not have access to it.'));
            } else {
                $rubricGateway = $container->get(RubricGateway::class);
                $rubric = $rubricGateway->getByID($gibbonRubricID);

                if (empty($rubric)) {
                    $page->addError(__('The specified record does not exist.'));
                } else {
                    try {
                        $data4 = array('gibbonPersonID' => $gibbonPersonID, 'gibbonCourseClassID' => $gibbonCourseClassID);
                        $sql4 = "SELECT DISTINCT surname, preferredName, gibbonPerson.gibbonPersonID, image_240 FROM gibbonPerson JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID) WHERE gibbonPerson.gibbonPersonID=:gibbonPersonID AND gibbonCourseClassID=:gibbonCourseClassID AND status='Full' AND (dateStart IS NULL OR dateStart<='".date('Y-m-d')."') AND (dateEnd IS NULL  OR dateEnd>='".date('Y-m-d')."') AND (role='Student' OR role='Student - Left')";
                        $result4 = $connection2->prepare($sql4);
                        $result4->execute($data4);
                    } catch (PDOException $e) {
                        echo "<div class='error'>".$e->getMessage().'</div>';
                    }

                    if ($result4->rowCount() != 1) {
                        $page->addError(__('The selected record does not exist, or you do not have access to it.'));
                    } else {
                        //Let's go!
                        $row4 = $result4->fetch();
                        
                        echo "<h2 class='inline-block align-top mr-4'>";
                        echo $rubric['name'].'<br/>';
                        echo "<span style='font-size: 65%; font-style: italic'>".Format::name('', $row4['preferredName'], $row4['surname'], 'Student', true).'</span>';
                        echo '</h2>';

                        echo Format::photo($row4['image_240'], 75, 'inline-block align-top mt-1');

                        $mark = ($_GET['mark'] ?? '') !== 'FALSE';
                        echo rubricView($guid, $connection2, $gibbonRubricID, $mark, $row4['gibbonPersonID'], 'atlColumn', 'atlColumnID', $atlColumnID,  $contextDBTableGibbonRubricIDField, 'name', $gibbonPersonID == $session->get('gibbonPersonID') ? '' : 'completeDate');

                        if ($mark && $gibbonPersonID !== $session->get('gibbonPersonID')) {
                            try {
                                $dataStudents = array('gibbonCourseClassID' => $gibbonCourseClassID);
                                $sqlStudents = "SELECT gibbonPerson.gibbonPersonID, dateStart FROM gibbonCourseClassPerson JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID) WHERE role='Student' AND gibbonCourseClassID=:gibbonCourseClassID AND status='Full' AND (dateStart IS NULL OR dateStart<='".date('Y-m-d')."') AND (dateEnd IS NULL  OR dateEnd>='".date('Y-m-d')."') AND gibbonCourseClassPerson.reportable='Y' ORDER BY surname, preferredName";
                                $resultStudents = $connection2->prepare($sqlStudents);
                                $resultStudents->execute($dataStudents);
                            } catch (PDOException $e) {
                            }

                            $prev = '';
                            $next = '';
                            while (($rowStudent = $resultStudents->fetch()) != null) {
                                if ($rowStudent['gibbonPersonID'] == $gibbonPersonID) {
                                    $nextRowStudent = $resultStudents->fetch();
                                    if ($nextRowStudent != null) {
                                        $next = $nextRowStudent['gibbonPersonID'];
                                    }
                                    break;
                                } else {
                                    $prev = $rowStudent['gibbonPersonID'];
                                }
                            }

                            echo '<div class="float-right mt-2">';
                            if (!empty($prev)) {
                                echo "<a href='#' onclick=\"tb_show('', '".$session->get('absoluteURL').'/fullscreen.php?q=/modules/ATL/atl_write_rubric.php&gibbonRubricID='.$gibbonRubricID."&gibbonCourseClassID=$gibbonCourseClassID&atlColumnID=".$atlColumnID.'&gibbonPersonID='.$prev."&mark=TRUE&type=" . $_GET['type'] . "&width=" . $_GET['width'] . "&height=" . $_GET['height'] . "')\"><img title='".__('Previous')."' src='./themes/".$session->get('gibbonThemeName')."/img/page_left.png'/></a>";
                            }

                            if (!empty($next)) {
                                echo "<a href='#' class='ml-1' onclick=\"tb_show('', '".$session->get('absoluteURL').'/fullscreen.php?q=/modules/ATL/atl_write_rubric.php&gibbonRubricID='.$gibbonRubricID."&gibbonCourseClassID=$gibbonCourseClassID&atlColumnID=".$atlColumnID.'&gibbonPersonID='.$next."&mark=TRUE&type=" . $_GET['type'] . "&width=" . $_GET['width'] . "&height=" . $_GET['height'] . "')\"><img title='".__('Next')."' src='./themes/".$session->get('gibbonThemeName')."/img/page_right.png'/></a>";
                            }
                            echo '</div>';
                        }
                    }
                }
            }
        }
    }
}
