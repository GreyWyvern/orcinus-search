<?php

require 'search.php';

?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Orcinus Site Search - Online example</title>

  <link rel="stylesheet" href="css/search.css">
</head>
<body>
  <h1>Orcinus Site Search - Online example</h1>

  <?php $_TEMPLATE->render(); ?> 

  <!-- Script files below are only required for Typeahead -->
  <script src="js/jquery-3.6.4.min.js"></script>
  <script src="js/typeahead.bundle.min.js"></script>
  <script src="js/search.js"></script>
</body>
</html>