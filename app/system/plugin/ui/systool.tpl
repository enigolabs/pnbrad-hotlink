{include file="sections/header.tpl"}

<style>


/* Style the tab */
.tab {
  overflow: hidden;
  border: 1px solid #ccc;
  background-color: #f1f1f1;
}

/* Style the buttons inside the tab */
.tab button {
  background-color: inherit;
  float: left;
  border: none;
  outline: none;
  cursor: pointer;
  padding: 14px 16px;
  transition: 0.3s;
  font-size: 17px;
}

/* Change background color of buttons on hover */
.tab button:hover {
  background-color: #;
}

/* Create an active/current tablink class */
.tab button.active {
  background-color: #fff;
}

/* Style the tab content */
.tabcontent {
  display: none;
  padding: 6px 12px;
  border: 1px solid #ccc;
  border-top: none;
}
</style>




  
<button class="tablink" onclick=" window.open('/admin/phpmyadmin/','_blank', 'orange')">phpMyAdmin</button>
<button class="tablink" onclick=" window.open('/admin/fileman/','_blank', 'green')">Filemanager</button>
<button class="tablink" onclick="openPage('rst', this, 'red')">Restart</button>



<div id="rst" class="tabcontent">
<form action="{$_url}plugin/systool" method="post">
<input type="hidden" name="restart" value="true">
<button type="submit" class="tablink" title="Restart container"
onclick="return confirm('Restart Server?')"><span
class="glyphicon glyphicon-refresh" aria-hidden="true"></span>Restart Server</button>
</form>
</div>
<br>
<br>
<h1>Cron Editor</h1>
<form action="{$_url}plugin/systool" method="POST">
    <textarea name="cron"  rows="4" cols="70">{$cront}</textarea>
	<br>
    <input type="submit" name="submit" value="Update Cron">
</form>


<script>
function openPage(pageName,elmnt,color) {
  var i, tabcontent, tablinks;
  tabcontent = document.getElementsByClassName("tabcontent");
  for (i = 0; i < tabcontent.length; i++) {
    tabcontent[i].style.display = "none";
  }
  tablinks = document.getElementsByClassName("tablink");
  for (i = 0; i < tablinks.length; i++) {
    tablinks[i].style.backgroundColor = "";
  }
  document.getElementById(pageName).style.display = "block";
  elmnt.style.backgroundColor = color;
}

// Get the element with id="defaultOpen" and click on it
document.getElementById("defaultOpen").click();
</script>

{include file="sections/footer.tpl"}