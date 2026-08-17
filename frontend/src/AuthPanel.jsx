import { useState } from 'react';

function AuthPanel() {
  const [email, setEmail] = useState('admin@example.com');
  const [password, setPassword] = useState('password123');
  const [role, setRole] = useState('admin');
  const [message, setMessage] = useState('');

  const handleLogin = async (event) => {
    event.preventDefault();

    const formData = new FormData();
    formData.append('email', email);
    formData.append('password', password);
    formData.append('role', role);

    const response = await fetch('../api/auth.php', {
      method: 'POST',
      body: formData
    });

    const data = await response.json();
    setMessage(data.message || 'Login result unavailable');
  };

  return (
    <div className="card">
      <h3>Authentication</h3>
      <form onSubmit={handleLogin} style={{ display: 'grid', gap: '10px' }}>
        <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" />
        <input value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Password" type="password" />
        <select value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="renter">Renter</option>
          <option value="owner">Owner</option>
          <option value="admin">Admin</option>
        </select>
        <button className="btn" type="submit">Login</button>
      </form>
      {message && <p className="small" style={{ marginTop: '10px' }}>{message}</p>}
    </div>
  );
}

export default AuthPanel;
