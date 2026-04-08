// Calendar Generation
const calendar = document.getElementById('calendar');
const currentDate = new Date();
const selectedDate = new Date();

function renderCalendar() {
  calendar.innerHTML = '';

  const firstDay = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
  const lastDay = new Date(selectedDate.getFullYear(), selectedDate.getMonth() + 1, 0);

  // Create Day Labels
  const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  daysOfWeek.forEach(day => {
    const dayLabel = document.createElement('div');
    dayLabel.textContent = day;
    dayLabel.classList.add('day-label');
    calendar.appendChild(dayLabel);
  });

  // Generate Empty Days for Offset
  for (let i = 0; i < firstDay.getDay(); i++) {
    const emptyDay = document.createElement('div');
    emptyDay.classList.add('empty-day');
    calendar.appendChild(emptyDay);
  }

  // Generate Days
  for (let day = 1; day <= lastDay.getDate(); day++) {
    const dayElement = document.createElement('div');
    dayElement.textContent = day;
    dayElement.classList.add('day');

    if (day === currentDate.getDate() &&
        selectedDate.getMonth() === currentDate.getMonth() &&
        selectedDate.getFullYear() === currentDate.getFullYear()) {
      dayElement.classList.add('selected');
    }

    dayElement.addEventListener('click', () => {
      document.querySelectorAll('.day').forEach(d => d.classList.remove('selected'));
      dayElement.classList.add('selected');
    });

    calendar.appendChild(dayElement);
  }
}

renderCalendar();

// Booking Button
const bookButton = document.getElementById('book-btn');
bookButton.addEventListener('click', () => {
  const purpose = document.getElementById('purpose').value;
  const time = document.querySelector('input[type="time"]').value;
  const selectedDayElement = document.querySelector('.day.selected');
  if (!selectedDayElement) {
    alert('Please select a date before booking.');
    return;
  }
  const selectedDay = selectedDayElement.textContent;
  alert(`Appointment booked for ${purpose} on ${selectedDate.getFullYear()}-${selectedDate.getMonth() + 1}-${selectedDay} at ${time}`);
});
