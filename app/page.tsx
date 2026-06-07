const beliefCards = [
  {
    title: 'Christ-centered worship',
    text: 'We gather to worship Jesus, grow in faith, and build a church family that serves with purpose.'
  },
  {
    title: 'Family-like community',
    text: 'The church is more than a building. It is a shared life of prayer, care, and encouragement.'
  },
  {
    title: 'Serving the neighborhood',
    text: 'From ministries to special services, we want our presence to bless people in practical ways.'
  }
];

const scheduleItems = [
  { day: 'Sunday', service: 'Morning Worship', time: '8:00 AM' },
  { day: 'Wednesday', service: 'Prayer Meeting', time: '6:30 PM' },
  { day: 'Friday', service: 'Youth and Ministry Night', time: '7:00 PM' },
  { day: 'Special', service: 'Live Mass and Events', time: 'Check announcements' }
];

const ministryItems = [
  'Handmaid\'s of the Lord',
  'Men\'s Ministry',
  'Music Ministry',
  'Usher & Usherette',
  'Junior Christ Ambassador'
];

export default function HomePage() {
  return (
    <main className="page-shell">
      <section className="hero">
        <div className="topbar">
          <div className="brand-block">
            <div className="brand-mark">HTCCC</div>
            <div>
              <p className="eyebrow">Holy Trinity Christian Community Church</p>
              <h1>A growing community centered on Christ.</h1>
            </div>
          </div>

          <nav className="nav-links" aria-label="Primary">
            <a href="#about">About</a>
            <a href="#beliefs">Beliefs</a>
            <a href="#schedule">Schedule</a>
            <a href="#ministries">Ministries</a>
            <a href="#contact">Contact</a>
          </nav>
        </div>

        <div className="hero-grid">
          <div className="hero-copy">
            <p className="lead">
              A simple first Vercel rewrite of the public church homepage. The PHP admin and database
              workflows still live in the legacy app and can be migrated next.
            </p>
            <div className="hero-actions">
              <a className="primary" href="#join-us">Join Us</a>
              <a className="secondary" href="#schedule">View Schedule</a>
            </div>
            <div className="hero-points">
              <span>Christ-centered worship</span>
              <span>Family-like community</span>
              <span>Serving the neighborhood</span>
            </div>
          </div>

          <aside className="hero-card">
            <p className="card-kicker">Live Mass</p>
            <h2>Sunday stream and special services</h2>
            <p>
              Vercel can host this frontend now. If you want live status, announcements, and schedules
              to remain dynamic, the next step is to move the data layer into an API or hosted database.
            </p>
            <div className="status-pill">Deploy-ready frontend scaffold</div>
          </aside>
        </div>
      </section>

      <section className="content-section" id="about">
        <div className="section-heading">
          <p className="eyebrow">About our church</p>
          <h2>A growing community rooted in worship and service.</h2>
          <p>
            Holy Trinity Christian Community Church began as a small gathering and grew into a place
            where people can encounter grace, discover their calling, and serve the world around them.
          </p>
        </div>

        <div className="feature-grid">
          {beliefCards.map((card) => (
            <article className="feature-card" key={card.title}>
              <h3>{card.title}</h3>
              <p>{card.text}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="content-section muted-panel" id="beliefs">
        <div className="section-heading compact">
          <p className="eyebrow">What we believe</p>
          <h2>Faith that shapes daily life.</h2>
        </div>

        <div className="belief-grid">
          <article className="quote-card">
            <h3>Our Beliefs</h3>
            <p>
              We believe in the authority of Scripture, the saving work of Christ, the power of prayer,
              and the call to live in grace and holiness.
            </p>
          </article>

          <article className="quote-card">
            <h3>We Believe</h3>
            <ul>
              <li>Jesus Christ is Lord.</li>
              <li>The church is a family.</li>
              <li>Service is part of discipleship.</li>
              <li>Every person matters to God.</li>
            </ul>
          </article>
        </div>
      </section>

      <section className="content-section" id="schedule">
        <div className="section-heading compact">
          <p className="eyebrow">Schedule</p>
          <h2>Regular gatherings.</h2>
        </div>

        <div className="schedule-grid">
          {scheduleItems.map((item) => (
            <article className="schedule-card" key={item.day + item.service}>
              <span className="day-chip">{item.day}</span>
              <h3>{item.service}</h3>
              <p>{item.time}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="content-section split-panel" id="ministries">
        <div>
          <p className="eyebrow">Ministries</p>
          <h2>Ways to get involved.</h2>
          <ul className="list-grid">
            {ministryItems.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </div>

        <div className="join-card" id="join-us">
          <p className="eyebrow">Join us</p>
          <h2>Find a place to belong.</h2>
          <p>
            If you are looking for a church home, start with worship, then connect with a ministry
            team, a service, or a small group.
          </p>
          <a className="primary" href="#contact">Contact the church</a>
        </div>
      </section>

      <footer className="footer" id="contact">
        <div>
          <p className="eyebrow">Contact</p>
          <h2>Holy Trinity Christian Community Church</h2>
          <p>Replace these placeholders with your live contact, location, and social links once the backend is migrated.</p>
        </div>

        <div className="contact-stack">
          <span>Location: add your church address</span>
          <span>Phone: add your contact number</span>
          <span>Email: add your church email</span>
        </div>
      </footer>
    </main>
  );
}