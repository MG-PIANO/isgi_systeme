import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Header } from './components/Header';
import { StudentSelector } from './components/StudentSelector';
import { CameraStudio } from './components/CameraStudio';
import { PhotoEditor } from './components/PhotoEditor';
import { BadgeRenderer } from './components/BadgeRenderer';
import type { Student, PhotoTransform } from './types/student';
import { fetchStudentsFromSupabase, resolveStudentPhotoUrl } from './lib/supabase';
import { Users, Camera, IdCard, ArrowLeft } from 'lucide-react';

type Step = 'select' | 'camera' | 'edit' | 'badge';

export const App: React.FC = () => {
  const [students, setStudents] = useState<Student[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isOnline, setIsOnline] = useState<boolean>(navigator.onLine);
  const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
  const [currentStep, setCurrentStep] = useState<Step>('select');

  const [capturedPhotoUrl, setCapturedPhotoUrl] = useState<string | null>(null);
  const [photoTransform, setPhotoTransform] = useState<PhotoTransform>({
    zoom: 1.0,
    offsetX: 0,
    offsetY: 0,
    rotation: 0,
    brightness: 100,
    contrast: 100
  });

  // Online / Offline monitor
  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  // Load students from Supabase
  const loadStudents = useCallback(async () => {
    setIsLoading(true);
    try {
      const data = await fetchStudentsFromSupabase();
      setStudents(data);
    } catch (e) {
      console.warn('Erreur chargement Supabase:', e);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadStudents();
  }, [loadStudents]);

  // Counts for header
  const { noPhotoCount } = useMemo(() => {
    let noPic = 0;
    for (const s of students) {
      if (!s.photo_url && !s.photo_cached) noPic++;
    }
    return { noPhotoCount: noPic };
  }, [students]);

  // When a student is selected from list
  const handleSelectStudent = async (student: Student) => {
    setSelectedStudent(student);

    // If student already has a photo, resolve it for immediate badge preview
    if (student.photo_cached) {
      setCapturedPhotoUrl(student.photo_cached);
      setCurrentStep('badge');
    } else if (student.photo_url) {
      const resolved = await resolveStudentPhotoUrl(student.photo_url);
      if (resolved) {
        setCapturedPhotoUrl(resolved);
        setCurrentStep('badge');
      } else {
        // If resolution failed, open camera
        setCurrentStep('camera');
      }
    } else {
      // No photo yet -> open camera directly!
      setCurrentStep('camera');
    }
  };

  // Explicit photo action clicked from card
  const handleStartCamera = (student: Student) => {
    setSelectedStudent(student);
    setPhotoTransform({
      zoom: 1.0,
      offsetX: 0,
      offsetY: 0,
      rotation: 0,
      brightness: 100,
      contrast: 100
    });
    setCurrentStep('camera');
  };

  // When photo is captured via camera or gallery
  const handlePhotoCaptured = (dataUrl: string) => {
    setCapturedPhotoUrl(dataUrl);
    setPhotoTransform({
      zoom: 1.0,
      offsetX: 0,
      offsetY: 0,
      rotation: 0,
      brightness: 100,
      contrast: 100
    });
    setCurrentStep('edit');
  };

  // Next student without photo (for rapid batch shooting!)
  const handleNextStudentWithoutPhoto = () => {
    // Find next student without photo
    const next = students.find(s => !s.photo_url && !s.photo_cached && s.id !== selectedStudent?.id);
    if (next) {
      setSelectedStudent(next);
      setCapturedPhotoUrl(null);
      setPhotoTransform({
        zoom: 1.0,
        offsetX: 0,
        offsetY: 0,
        rotation: 0,
        brightness: 100,
        contrast: 100
      });
      setCurrentStep('camera');
    } else {
      // All students have photos! Return to list
      setCurrentStep('select');
      loadStudents();
    }
  };

  return (
    <div className="flex flex-col min-h-screen bg-slate-950 text-slate-100">
      {/* Sticky Header */}
      <Header
        isOnline={isOnline}
        studentsCount={students.length}
        noPhotoCount={noPhotoCount}
        onRefresh={loadStudents}
        isLoading={isLoading}
      />

      {/* Main Content Area */}
      <main className="flex-1 max-w-lg mx-auto w-full p-4 flex flex-col">
        {/* Step Navigation Bar (when student is selected and not in camera mode) */}
        {selectedStudent && currentStep !== 'select' && currentStep !== 'camera' && (
          <div className="flex items-center justify-between mb-3 px-1">
            <button
              onClick={() => setCurrentStep('select')}
              className="flex items-center gap-1.5 text-xs text-slate-400 hover:text-white py-1 px-2 rounded-lg bg-slate-900 border border-slate-800"
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              <span>Changer d'étudiant</span>
            </button>

            {/* Step Breadcrumbs */}
            <div className="flex items-center gap-1 text-[11px] font-semibold">
              <button
                onClick={() => setCurrentStep('camera')}
                className="px-2 py-0.5 rounded text-slate-400 hover:text-slate-200"
              >
                1. Photo
              </button>
              <span className="text-slate-600">›</span>
              <button
                onClick={() => setCurrentStep('edit')}
                className={`px-2 py-0.5 rounded ${currentStep === 'edit' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-slate-200'}`}
              >
                2. Recadrer
              </button>
              <span className="text-slate-600">›</span>
              <button
                onClick={() => setCurrentStep('badge')}
                className={`px-2 py-0.5 rounded ${currentStep === 'badge' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-slate-200'}`}
              >
                3. Badge
              </button>
            </div>
          </div>
        )}

        {/* Step 1: Student Selector */}
        {currentStep === 'select' && (
          <StudentSelector
            students={students}
            selectedStudent={selectedStudent}
            onSelectStudent={handleSelectStudent}
            onStartCameraForStudent={handleStartCamera}
            isLoading={isLoading}
          />
        )}

        {/* Step 2: Camera Studio Modal */}
        {currentStep === 'camera' && selectedStudent && (
          <CameraStudio
            student={selectedStudent}
            onPhotoCaptured={handlePhotoCaptured}
            onClose={() => setCurrentStep('select')}
          />
        )}

        {/* Step 3: Photo Adjuster / Cropper */}
        {currentStep === 'edit' && selectedStudent && capturedPhotoUrl && (
          <PhotoEditor
            student={selectedStudent}
            photoUrl={capturedPhotoUrl}
            transform={photoTransform}
            onChangeTransform={setPhotoTransform}
            onConfirmBadge={() => setCurrentStep('badge')}
            onRetakePhoto={() => setCurrentStep('camera')}
          />
        )}

        {/* Step 4: Badge Real-time Renderer & Supabase Sync */}
        {currentStep === 'badge' && selectedStudent && capturedPhotoUrl && (
          <BadgeRenderer
            student={selectedStudent}
            photoUrl={capturedPhotoUrl}
            transform={photoTransform}
            onEditPhoto={() => setCurrentStep('edit')}
            onRetakePhoto={() => setCurrentStep('camera')}
            onNextStudent={handleNextStudentWithoutPhoto}
          />
        )}
      </main>
    </div>
  );
};
export default App;
