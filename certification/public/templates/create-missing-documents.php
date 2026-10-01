<!-- Your modal content here -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">

<div class="modal-content modal-enrollment" id="modal-content">
    <div id="modal-contraseña" class="modal" style="overflow: auto; padding: 0 !important">
        <div class="modal-content modal-enrollment">
            <span id="close-modal-enrollment" style="float: right; cursor: pointer"><span
                    class='dashicons dashicons-no-alt'></span></span>
            <div class="modal-body" id="content-pdf">
                <div id="part3" style="font-size: 12px; color: #000">
                    <div style="padding: 0 !important; text-align: center;">
                        <img style="width: 240px; margin: auto; padding: 10px;" src="http://portal.americanelite.school/wp-content/uploads/2025/06/cropped-cropped-cropped-AMERICAN-ELITE-SCHOOL_LOGOTIPO-COLOR-3-scaled.png">
                        <span style="font-size: 12px;">3105 NW 107 Avenue, Doral, FL 33172 | (786) 361 – 9307 |
                            www.American-elite.us</span>
                        <h4 style="padding: 4px; text-align: center; font-weight: bold; font-size: 20px;">MISSING
                            DOCUMENT
                            COMMITMENT LETTER</h4>
                    </div>
                    <div style="padding: 10px 40px; ">
                        <input type="hidden" name="student_user_id" value="<?php echo $student_id ?>">
                        <input type="hidden" name="parent_user_id" value="<?php echo $partner_id ?>">
                        <input type="hidden" name="show_parent_info" value="<?php echo $show_parent_info ?>">
                        <input type="hidden" name="document_id" value="MISSING DOCUMENT">
                        <input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1">
                        <!-- <h4
                            style="background-color: #002fbd; color: white; padding: 4px; text-align: center; font-weight: 600;">
                            STUDENT PERSONAL INFORMATION</h4> -->

                        <div style="font-size: 15px">
                            <div style="margin-bottom: 20px;">
                                This document serves as a commitment on behalf of <?= strtoupper($student->last_name) . ' ' . strtoupper($student->middle_last_name) ?>, <?= strtoupper($student->name) . ' ' . strtoupper($student->middle_name) ?>, to
                                submit, within
                                the specified deadlines set by American Elite School, the missing documents required for
                                complete
                                registration and graduation.
                            </div>

                            <div style="margin-bottom: 20px; margin-left: 20px;">
                                <ul>
                                    <?php foreach ($documents as $key => $document) { ?>
                                        <li><?= $key + 1 ?>. <?= $document->document_id ?></li>
                                    <?php } ?>
                                </ul>
                            </div>

                            <div style="margin-bottom: 20px;">
                                The academic high school program will begin for the 2026-2027 academic year on August
                                18, 2026. As it is
                                the responsibility of the representative to submit the above-mentioned documents, by
                                signing this
                                letter, they acknowledge and accept their commitment to comply with all instructions
                                provided by AES
                                regarding the required documents and their respective deadlines.
                            </div>

                            <div style="margin-bottom: 20px;">
                                Failure to comply with the foregoing, the student may be penalized for being withdrawn
                                from the program
                                until he delivers the missing document or documents.
                            </div>
                        </div>

                        <?php if (!wp_is_mobile()) { ?>
                            <input type="hidden" name="auto_signature_student" value="0">
                            <div style="display: flex">
                                <div style="flex: 50%">
                                    <div>
                                        <div style="padding: 8px;"><strong>Signature of applicant:</strong>
                                            <br> <?php echo $user['student_full_name'] ?>
                                        </div>
                                    </div>
                                    <div style="position: relative; padding: 8px;" id="signature-pad-student">
                                        <canvas id="signature-student" width="100%" height="200"
                                            style="border: 1px solid gray; margin: auto !important; background-color: #ffff005c"></canvas>
                                        <div id="sign-here-student"
                                            style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                                            <span>SIGN HERE</span>
                                        </div>
                                    </div>
                                    <button id="clear-student" style="width: 100%;">Clear</button>
                                    <button id="generate-signature-student" style="width: 100%;"
                                        onclick="autoSignature('signature-pad-student', 'signature-text-student', 'generate-signature-student', 'clear-student')">Generate
                                        signature automatically</button>
                                    <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                                        id="signature-text-student">
                                        <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                                            <?php echo $user['student_signature'] ?>
                                        </div>
                                    </div>
                                    <button id="clear-student-signature" style="width: 100%; display: none">Cancel</button>
                                </div>
                                <?php if ($show_parent_info == 1) { ?>
                                    <input type="hidden" name="auto_signature_parent" value="0">
                                    <div style="flex: 50%">
                                        <div>
                                            <div style="padding: 8px;"><strong>Signature of Parent/Legal Guardian:</strong>
                                                <br> <?php echo $user['parent_full_name'] ?>
                                            </div>
                                        </div>
                                        <div style="position: relative; padding: 8px;" id="signature-pad-parent">
                                            <canvas id="signature-parent" width="100%" height="200"
                                                style="border: 1px solid gray; margin: auto !important;  background-color: #ffff005c"></canvas>
                                            <div id="sign-here-parent"
                                                style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                                                <span>SIGN HERE</span>
                                            </div>
                                        </div>
                                        <button id="clear-parent" style="width: 100%;">Clear</button>
                                        <button id="generate-signature-parent" style="width: 100%;"
                                            onclick="autoSignature('signature-pad-parent', 'signature-text-parent', 'generate-signature-parent', 'clear-parent')">Generate
                                            signature automatically</button>
                                        <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                                            id="signature-text-parent">
                                            <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                                                <?php echo $user['parent_full_name'] ?>
                                            </div>
                                        </div>
                                        <button id="clear-parent-signature" style="width: 100%; display: none">Cancel</button>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } else { ?>
                            <input type="hidden" name="auto_signature_student" value="0">
                            <div style="display: block">
                                <div style="flex: auto">
                                    <div>
                                        <div style="padding: 8px;"><strong>Applicant Full Name:</strong>
                                            <br> <?php echo $user['student_full_name'] ?>
                                        </div>
                                    </div>
                                    <div style="position: relative; padding: 8px;" id="signature-pad-student">
                                        <canvas id="signature-student" width="100%" height="200"
                                            style="border: 1px solid gray; margin: auto !important;  background-color: #ffff005c"></canvas>
                                        <div id="sign-here-student"
                                            style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                                            <span>SIGN HERE</span>
                                        </div>
                                    </div>
                                    <button id="clear-student" style="width: 100%;">Clear</button>
                                    <button id="generate-signature-student" style="width: 100%;"
                                        onclick="autoSignature('signature-pad-student', 'signature-text-student', 'generate-signature-student', 'clear-student')">Generate
                                        signature automatically</button>
                                    <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                                        id="signature-text-student">
                                        <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                                            <?php echo $user['student_signature'] ?>
                                        </div>
                                    </div>
                                    <button id="clear-student-signature" style="width: 100%; display: none">Cancel</button>
                                </div>
                                <?php if ($show_parent_info == 1) { ?>
                                    <input type="hidden" name="auto_signature_parent" value="0">
                                    <div style="flex: auto">
                                        <div>
                                            <div style="padding: 8px;"><strong>Parent/Legal Guardian Full Name:</strong>
                                                <br> <?php echo $user['parent_full_name'] ?>
                                            </div>
                                        </div>
                                        <div style="position: relative; padding: 8px;" id="signature-pad-parent">
                                            <canvas id="signature-parent" width="100%" height="200"
                                                style="border: 1px solid gray; margin: auto !important;  background-color: #ffff005c"></canvas>
                                            <div id="sign-here-parent"
                                                style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                                                <span>SIGN HERE</span>
                                            </div>
                                        </div>
                                        <button id="clear-parent" style="width: 100%;">Clear</button>
                                        <button id="generate-signature-parent" style="width: 100%;"
                                            onclick="autoSignature('signature-pad-parent', 'signature-text-parent', 'generate-signature-parent', 'clear-parent')">Generate
                                            signature automatically</button>
                                        <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                                            id="signature-text-parent">
                                            <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                                                <?php echo $user['parent_full_name'] ?>
                                            </div>
                                        </div>
                                        <button id="clear-parent-signature" style="width: 100%; display: none">Cancel</button>
                                    </div>
                                <?php } ?>

                            </div>
                        <?php } ?>
                        <div style="display: flex">
                            <div style="flex: auto; padding: 8px;"><strong>Date:</strong>
                                <br> <?php echo $user['today'] ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; display: block">
                <button type="button" class="submit button-create-enrollment" id="saveSignatures">Save</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
        integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</div>