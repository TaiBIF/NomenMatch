
function htmlDecode(input) {
  var doc = new DOMParser().parseFromString(input, "text/html");
  return doc.documentElement.textContent;
}


$('.row_result').mouseenter(function() {

  if ($(this).find('.name_diff').length == 0) {
    var input_name = $(this).parent().find("span[name_cleaned]").attr('name_cleaned');
    var td_matched = $(this).find('.matched')[0];
    var matched = htmlDecode(td_matched.innerHTML);
    var diff = JsDiff.diffChars(input_name, matched);
    var diff_matched = document.createElement('div');
    // console.log($(this).find('.matched'));
//*
    diff.forEach(function(part){

    // green for additions, red for deletions
    // grey for common parts
      var color = part.added ? 'blue' :
                  part.removed ? '#DC143C' : 'grey';
      var display = part.removed ? 'none' : '';
      var span = document.createElement('span');
      span.style.color = color;
      span.style.display = display;
      span.appendChild(document.createTextNode(part.value));
      diff_matched.appendChild(span);
    });
//*/

    // td_matched.appendChild(diff_matched);
    $(this).find('.matched').eq(0).html('');
    $(this).find('.matched').eq(0).html(diff_matched.innerHTML);
    td_matched.setAttribute("class", "name_diff");
  }
});

