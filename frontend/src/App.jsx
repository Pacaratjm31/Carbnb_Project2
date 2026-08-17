import { useEffect, useState } from 'react';
import './styles.css';
import { adminStats, recentPayments } from './adminData';
import AuthPanel from './AuthPanel';
import RegisterPanel from './RegisterPanel';

function App() {
  const [status, setStatus] = useState('Loading...');

  useEffect(() => {
    fetch('../api/health.php')
      .then((res) => res.json())
      .then((data) => {
        setStatus(data.success ? 'API online' : 'API offline');
      })
      .catch(() => setStatus('API unreachable'));
  }, []);

  return (
    <div className="app-shell">
      <div className="card hero">
        <div>
          <h1>Carbnb Renter Dashboard</h1>
          <p className="small">Book, pay, and manage your rentals from one place.</p>
        </div>
        <a className="btn" href="../renter/paid.php?booking_id=1">Go to Payment</a>
      </div>

      <div className="card">
        <h3>System Status</h3>
        <p className="small">Backend connection: {status}</p>
      </div>

      <AuthPanel />
      <RegisterPanel />

      <div className="card">
        <h3>Admin Overview</h3>
        <div className="grid">
          {adminStats.map((item) => (
            <div className="stat" key={item.label}>
              <h4>{item.value}</h4>
              <p className="small">{item.label}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="card">
        <h3>Recent Payments</h3>
        <ul>
          {recentPayments.map((payment) => (
            <li key={payment.id}>
              {payment.renter} — {payment.amount} — {payment.status}
            </li>
          ))}
        </ul>
      </div>

      <div className="grid">
        <div className="stat">
          <h4>Upcoming Trips</h4>
          <p className="small">Track your active rentals</p>
        </div>
        <div className="stat">
          <h4>Payments</h4>
          <p className="small">Secure checkout and receipts</p>
        </div>
        <div className="stat">
          <h4>Support</h4>
          <p className="small">Fast assistance for renters</p>
        </div>
      </div>
    </div>
  );
}

export default App;
