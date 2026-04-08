let currentSlide = 0;
const slides = document.querySelectorAll(".slide");

function moveSlide(direction) {
    slides[currentSlide].classList.remove("active");
    currentSlide = (currentSlide + direction + slides.length) % slides.length;
    slides[currentSlide].classList.add("active");
}

function autoSlide() {
    moveSlide(1);
}

function toggleMenu() {
    document.querySelector("nav ul").classList.toggle("active");
}

let scrollAmount = 0;
    const slider = document.querySelector('.gallery-slider');
    const itemWidth = document.querySelector('.gallery-item').offsetWidth + 20;

    function moveGallery(direction) {
        scrollAmount += direction * itemWidth;
        slider.style.transform = `translateX(-${scrollAmount}px)`;
    }
    const readMoreBtn = document.getElementById("readMoreBtn");
    const floatingForm = document.getElementById("floatingForm");
    const closeFormBtn = document.getElementById("closeFormBtn");

    // Open the floating form when "Read more" is clicked
    readMoreBtn.addEventListener("click", function(event) {
        event.preventDefault(); // Prevents default behavior of the link
        floatingForm.style.display = "flex"; // Show the floating form
    });

    // Close the form when the close button is clicked
    closeFormBtn.addEventListener("click", function() {
        floatingForm.style.display = "none"; // Hide the floating form
    });




setInterval(autoSlide, 3000);

function openModal() {
    document.getElementById('modal').style.display = 'flex';
  }
  
  function closeModal() {
    document.getElementById('modal').style.display = 'none';
  }
  