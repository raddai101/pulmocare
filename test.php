<<?php
$mdp = password_hash('Admin123&', PASSWORD_DEFAULT);
echo $mdp;
$hash = $mdp;
// $verify = password_verify('Django101py&', '$2y$12$NLFl2gS3JrOY2bzSxX.auuvfk6e0FoCnaPdKulDMEsPWp1ppYwE5q');
// echo "verify " .$verify;
if (password_verify('Admin123&','$2y$10$KDfrF8A28vFDNJtEI4hk/.h.M9YGizGeCY2HjGFqMaTIEn4ykoXwe')){
    echo "\nmdp correct";
}else{
    echo "mdp incorrect";
}
?>