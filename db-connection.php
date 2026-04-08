<?php
    $db_server = "localhost";
    $db_username = "root";
    $db_password = "";
    $db_name = "htccc-data-base";

    $db_connection = mysqli_connect($db_server, $db_username, 
                                    $db_password, $db_name);
            
        if($db_connection){
            echo "";
        }
        else{
            echo "Failed to connect to database";
        }
?> 