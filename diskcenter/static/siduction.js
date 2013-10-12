// Click a button and set the focus to a given field.
// @param button:	name of the button to click
// @param field:    name of the field which gets the focus
function clickAndSet(button, field){
	document.getElementsByName(button)[0].click();
	window.setTimeout(function() { document.getElementsByName(field)[0].focus() }, 200);
	window.setTimeout(function() { document.getElementsByName(field)[0].focus() }, 1000);
}

// Clicks a button
// @param button:	name of the button to click
function autoClick(button){
	document.getElementsByName(button)[0].click();
}
	
	