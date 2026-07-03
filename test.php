<<?php
// $mdp = password_hash('Django101py&', PASSWORD_DEFAULT);
// echo $mdp;
// $hash = $mdp;
// $verify = password_verify('Django101py&', '$2y$12$NLFl2gS3JrOY2bzSxX.auuvfk6e0FoCnaPdKulDMEsPWp1ppYwE5q');
// echo "verify " .$verify;
if (password_verify('Django101py&','$2y$12$NLFl2gS3JrOY2bzSxX.auuvfk6e0FoCnaPdKulDMEsPWp1ppYwE5q')){
    echo "\nmdp correct";
}else{
    echo "mdp incorrect";
}
?>